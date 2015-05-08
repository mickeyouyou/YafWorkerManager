<?php
namespace Bin;
use \Yaf\Loader;
declare(ticks = 1);

error_reporting(E_ALL | E_STRICT);

/**
 * Class that handles all the process management
 */
abstract class WorkerManager {

    /**
     * Log levels can be enabled from the command line with -v, -vv, -vvv
     */
    const LOG_LEVEL_INFO = 1;
    const LOG_LEVEL_PROC_INFO = 2;
    const LOG_LEVEL_WORKER_INFO = 3;
    const LOG_LEVEL_DEBUG = 4;
    const LOG_LEVEL_CRAZY = 5;

    /**
     * Default config section name
     */
    const DEFAULT_CONFIG = "WorkerManager";

    /**
     * Defines job priority limits
     */
    const MIN_PRIORITY = -5;
    const MAX_PRIORITY = 5;

    /**
     * Holds the worker configuration
     */
    protected $config = array();

    /**
     * Boolean value that determines if the running code is the parent or a child
     */
    protected $isparent = true;

    /**
     * When true, workers will stop look for jobs and the parent process will
     * kill off all running children
     */
    protected $stop_work = false;

    /**
     * The timestamp when the signal was received to stop working
     */
    protected $stop_time = 0;

    /**
     * The filename to log to
     */
    protected $log_file;

    /**
     * Holds the resource for the log file
     */
    protected $log_file_handle;

    /**
     * Flag for logging to syslog
     */
    protected $log_syslog = false;

    /**
     * Verbosity level for the running script. Set via -v option
     */
    protected $verbose = 0;

    /**
     * The array of running child processes
     */
    protected $children = array();

    /**
     * The array of jobs that have workers running
     */
    protected $jobs = array();

    /**
     * The PID of the running process. Set for parent and child processes
     */
    protected $pid = 0;

    /**
     * The PID of the parent process, when running in the forked helper.
     */
    protected $parent_pid = 0;

    /**
     * PID file for the parent process
     */
    protected $pid_file = "";

    /**
     * PID of helper child
     */
    protected $helper_pid = 0;

    /**
     * The user to run as
     */
    protected $user = null;

    /**
     * If true, the worker code directory is checked for updates and workers
     * are restarted automatically.
     */
    protected $check_code = false;

    /**
     * Holds the last timestamp of when the code was checked for updates
     */
    protected $last_check_time = 0;

    /**
     * When forking helper children, the parent waits for a signal from them
     * to continue doing anything
     */
    protected $wait_for_signal = false;

    /**
     * Directory where worker functions are found
     */
    protected $worker_dir = "";

    /**
     * Number of workers that do all jobs
     */
    protected $do_all_count = 0;

    /**
     * Maximum time a worker will run
     */
    protected $max_run_time = 3600;

    /**
     * +/- number of minutes workers will delay before restarting
     * this prevents all your workers from restarting at the same
     * time which causes a connection stampeded on your daemons
     * So, a max_run_time of 3600 and worker_restart_splay of 600 means
     * a worker will self restart between 3600 and 4200 seconds after
     * it is started.
     *
     * This does not affect the time the parent process waits on children
     * when shutting down.
     */
    protected $worker_restart_splay = 600;

    /**
     * Maximum number of jobs this worker will do before quitting
     */
    protected $max_job_count = 0;

    /**
     * Maximum job iterations per worker
     */
    protected $max_runs_per_worker = null;

    /**
     * Number of times this worker has run a job
     */
    protected $job_execution_count = 0;

    /**
     * Servers that workers connect to
     */
    protected $servers = array();

    /**
     * List of functions available for work
     */
    protected $functions = array();

    /**
     * Function/Class prefix
     */
    protected $prefix = "";

    /**
     * @var Loader Class Auto Load
     */
    protected $loader;
    /**
     * Creates the manager and gets things going
     *
     */
    public function __construct(Loader $loader) {

        if(!function_exists("posix_kill")){
            $this->show_help("The function posix_kill was not found. Please ensure POSIX functions are installed");
        }

        if(!function_exists("pcntl_fork")){
            $this->show_help("The function pcntl_fork was not found. Please ensure Process Control functions are installed");
        }

        $this->loader = $loader;
        $this->pid = getmypid();

    }


    /**
     * Run the manager based on the config either passed in via command line or the config array.
     *
     * @param  array  $config Config to run
     */
    public function run($config = array())
    {
        /**
         * Parse command line options. Loads the config file as well
         */
        $this->getopt($config);

        /**
         * Register signal listeners
         */
        $this->register_ticks();

        /**
         * Load up the workers
         */
        $this->load_workers();

        if(empty($this->functions)){
            $this->log("No workers found");
            posix_kill($this->pid, SIGUSR1);
            exit();
        }

        /**
         * Validate workers in the helper process
         */
        $this->fork_me("validate_workers");

        $this->log("Started with pid $this->pid", WorkerManager::LOG_LEVEL_PROC_INFO);

        /**
         * Start the initial workers and set up a running environment
         */
        $this->bootstrap();

        $this->process_loop();

        /**
         * Kill the helper if it is running
         */
        if(isset($this->helper_pid)){
            posix_kill($this->helper_pid, SIGKILL);
        }

        $this->log("Exiting");

    }

    protected function process_loop() {

        /**
         * Main processing loop for the parent process
         */
        while(!$this->stop_work || count($this->children)) {

            $status = null;

            /**
             * Check for exited children
             */
            $exited = pcntl_wait( $status, WNOHANG );

            /**
             * We run other children, make sure this is a worker
             */
            if(isset($this->children[$exited])){
                /**
                 * If they have exited, remove them from the children array
                 * If we are not stopping work, start another in its place
                 */
                if($exited) {
                    $worker = $this->children[$exited]['job'];
                    unset($this->children[$exited]);
                    $code = pcntl_wexitstatus($status);
                    $this->log("Child $exited exited with error code of $code (".implode(",", $worker).")", WorkerManager::LOG_LEVEL_PROC_INFO);
                    if(!$this->stop_work){
                        $this->start_worker($worker);
                    }
                }
            }


            if($this->stop_work && time() - $this->stop_time > 60){
                $this->log("Children have not exited, killing.", WorkerManager::LOG_LEVEL_PROC_INFO);
                $this->stop_children(SIGKILL);
            } else {
                /**
                 *  If any children have been running 150% of max run time, forcibly terminate them
                 */
                if (!empty($this->children)) {
                    foreach($this->children as $pid => $child) {
                        if (!empty($child['start_time']) && time() - $child['start_time'] > $this->max_run_time * 1.5) {
                            $this->log("Child $pid has been running too long. Forcibly killing process. (".implode(",", $child['job']).")", WorkerManager::LOG_LEVEL_PROC_INFO);
                            posix_kill($pid, SIGKILL);
                        }
                    }
                }
            }

            /**
             * php will eat up your cpu if you don't have this
             */
            usleep(50000);

        }

    }

    /**
     * Handles anything we need to do when we are shutting down
     *
     */
    public function __destruct() {
        if($this->isparent){
            if(!empty($this->pid_file) && file_exists($this->pid_file)){
                if(!unlink($this->pid_file)) {
                    $this->log("Could not delete PID file", WorkerManager::LOG_LEVEL_PROC_INFO);
                }
            }
        }
    }

    /**
     * Parses the command line options
     *
     * @param array $config <ul>
     *   <li><b>auto_update:</b> Automatically check for new worker code.</li>
     *   <li><b>count:</b> Number of workers to start that do all jobs.</li>
     *   <li><b>daemon:</b> Daemon, detach and run in the background when true.</li>
     *   <li><b>file:</b> File to load the configuration from.</li>
     *   <li><b>host:</b> Host to connect to in server:port format, although port is optional.</li>
     *   <li><b>log_file:</b> Log output to LOG_FILE or use keyword 'syslog' for syslog support.</li>
     *   <li><b>max_runs_per_worker:</b> Maximum job iterations per worker.</li>
     *   <li><b>max_worker_lifetime:</b> Maximum number of seconds for a worker to live.</li>
     *   <li><b>pid_file:</b> File to write process ID to.</li>
     *   <li><b>prefix:</b> Job Class prefix to use.</li>
     *   <li><b>timeout:</b> Maximum number of seconds server should wait for a worker to complete work before timing out and reissuing work to another worker.</li>
     *   <li><b>worker_dir:</b> Directory where workers are located, defaults to ./workers.  If you are using PECL, you can provide multiple directories separated by comma.</li>
     *   <li><b>verbose:</b> Set the log verbosity.  See the LOG_LEVEL_* constants.</li>
     * </ul>
     */
    protected function getopt($config = array()) {

        $opts = getopt("ac:dD:h:Hl:o:p:P:u:v::w:r:x:Z");

        if(isset($opts["H"])){
            $this->show_help();
        }

        /**
         * parse the config file
         */
        if ( ! empty($config) && is_array($config) ) {
            /**
             * set the config to the passed in array
             * @todo  we should probably do more checks to validate the values
             */
            $this->config = array_merge($this->config, $config);
        }

        if(isset($opts["c"])) {
            $this->config['file'] = $opts['c'];
        }

        if (isset($this->config['file'])) {
            if (file_exists($this->config['file'])) {
                $this->parse_config($this->config['file']);
            }
            else {
                $this->show_help("Config file {$this->config['file']} not found.");
            }
        }

        /**
         * command line opts always override config file
         */
        if (isset($opts['P'])) {
            $this->config['pid_file'] = $opts['P'];
        }

        if(isset($opts["l"])){
            $this->config['log_file'] = $opts["l"];
        }

        if (isset($opts['a'])) {
            $this->config['auto_update'] = 1;
        }

        if (isset($opts['w'])) {
            $this->config['worker_dir'] = $opts['w'];
        }

        if (isset($opts['x'])) {
            $this->config['max_worker_lifetime'] = (int)$opts['x'];
        }

        if (isset($opts['r'])) {
            $this->config['max_runs_per_worker'] = (int)$opts['r'];
        }

        if (isset($opts['D'])) {
            $this->config['count'] = (int)$opts['D'];
        }

        if (isset($opts['t'])) {
            $this->config['timeout'] = $opts['t'];
        }

        if (isset($opts['h'])) {
            $this->config['host'] = $opts['h'];
        }

        if (isset($opts['p'])) {
            $this->prefix = $opts['p'];
        } elseif(!empty($this->config['prefix'])) {
            $this->prefix = $this->config['prefix'];
        } elseif(defined('NET_WORKER_JOB_CLASS_PREFIX')) {
            $this->prefix = NET_WORKER_JOB_CLASS_PREFIX;
        } else {
            $this->prefix = 'Net_Worker_Job_';
        }

        if(isset($opts['u'])){
            $this->user = $opts['u'];
        } elseif(isset($this->config["user"])){
            $this->user = $this->config["user"];
        }

        /**
         * If we want to daemonize, fork here and exit
         */
        if(isset($opts["d"]) || ! empty($this->config['daemon'])) {
            $pid = pcntl_fork();
            if($pid>0){
                $this->isparent = false;
                exit();
            }
            $this->pid = getmypid();
            posix_setsid();
        }

        if(!empty($this->config['pid_file'])){
            $fp = @fopen($this->config['pid_file'], "w");
            if($fp){
                fwrite($fp, $this->pid);
                fclose($fp);
            } else {
                $this->show_help("Unable to write PID to {$this->config['pid_file']}");
            }
            $this->pid_file = $this->config['pid_file'];
        }

        if(!empty($this->config['log_file'])){
            if($this->config['log_file'] === 'syslog'){
                $this->log_syslog = true;
            } else {
                $this->log_file = $this->config['log_file'];
                $this->open_log_file();
            }
        }

        if (isset($this->config['verbose'])) {
            $this->verbose = (int) $this->config['verbose'] ?: WorkerManager::LOG_LEVEL_INFO;
        }

        if(isset($opts["v"])){
            switch($opts["v"]){
                case false:
                    $this->verbose = WorkerManager::LOG_LEVEL_INFO;
                    break;
                case "v":
                    $this->verbose = WorkerManager::LOG_LEVEL_PROC_INFO;
                    break;
                case "vv":
                    $this->verbose = WorkerManager::LOG_LEVEL_WORKER_INFO;
                    break;
                case "vvv":
                    $this->verbose = WorkerManager::LOG_LEVEL_DEBUG;
                    break;
                case "vvvv":
                default:
                    $this->verbose = WorkerManager::LOG_LEVEL_CRAZY;
                    break;
            }
        }

        if($this->user) {
            $user = posix_getpwnam($this->user);
            if (!$user || !isset($user['uid'])) {
                $this->show_help("User ({$this->user}) not found.");
            }

            /**
             * Ensure new uid can read/write pid and log files
             */
            if(!empty($this->pid_file)){
                if(!chown($this->pid_file, $user['uid'])){
                    $this->log("Unable to chown PID file to {$this->user}", WorkerManager::LOG_LEVEL_PROC_INFO);
                }
            }
            if(!empty($this->log_file_handle)){
                if(!chown($this->log_file, $user['uid'])){
                    $this->log("Unable to chown log file to {$this->user}", WorkerManager::LOG_LEVEL_PROC_INFO);
                }
            }

            posix_setuid($user['uid']);
            if (posix_geteuid() != $user['uid']) {
                $this->show_help("Unable to change user to {$this->user} (UID: {$user['uid']}).");
            }
            $this->log("User set to {$this->user}", WorkerManager::LOG_LEVEL_PROC_INFO);
        }

        if(!empty($this->config['auto_update'])){
            $this->check_code = true;
        }

        if(!empty($this->config['worker_dir'])){
            $this->worker_dir = $this->config['worker_dir'];
        } else {
            $this->worker_dir = "./workers";
        }

        $dirs = is_array($this->worker_dir) ? $this->worker_dir : explode(",", $this->worker_dir);
        foreach($dirs as &$dir){
            $dir = trim($dir);
            if(!file_exists($dir)){
                $this->show_help("Worker dir ".$dir." not found");
            }
        }
        unset($dir);

        if(isset($this->config['max_worker_lifetime']) && (int)$this->config['max_worker_lifetime'] > 0){
            $this->max_run_time = (int)$this->config['max_worker_lifetime'];
        } else {
            $this->config['max_worker_lifetime'] = $this->max_run_time;
        }

        if(isset($this->config['worker_restart_splay']) && (int)$this->config['worker_restart_splay'] > 0){
            $this->worker_restart_splay = (int)$this->config['worker_restart_splay'];
        }

        if(isset($this->config['count']) && (int)$this->config['count'] > 0){
            $this->do_all_count = (int)$this->config['count'];
        }

        if(!empty($this->config['host'])){
            if(!is_array($this->config['host'])){
                $this->servers = explode(",", $this->config['host']);
            } else {
                $this->servers = $this->config['host'];
            }
        } else {
            $this->show_help("Connect to HOST and PORT Must Set, Use -h Option");
        }

        if (!empty($this->config['include']) && $this->config['include'] != "*") {
            $this->config['include'] = explode(",", $this->config['include']);
        } else {
            $this->config['include'] = array();
        }

        if (!empty($this->config['exclude'])) {
            $this->config['exclude'] = explode(",", $this->config['exclude']);
        } else {
            $this->config['exclude'] = array();
        }

        /**
         * Debug option to dump the config and exit
         */
        if(isset($opts["Z"])){
            print_r($this->config);
            exit();
        }

    }

    /**
     * Parses the config file
     *
     * @param   string    $file     The config file. Just pass so we don't have
     *                              to keep it around in a var
     */
    protected function parse_config($file) {

        $this->log("Loading configuration from $file");

        if (substr($file, -4) == ".php"){

            require $file;

        } elseif(substr($file, -4) == ".ini"){

            $worker_config = parse_ini_file($file, true);

        }

        if (empty($worker_config)){
            $this->show_help("No configuration found in $file");
        }

        if (isset($worker_config[self::DEFAULT_CONFIG])) {
            $this->config = $worker_config[self::DEFAULT_CONFIG];
            $this->config['functions'] = array();
        }

        foreach($worker_config as $function=>$data){

            if (strcasecmp($function, self::DEFAULT_CONFIG) != 0) {
                $this->config['functions'][$function] = $data;
                $this->config['services'][$data['service']][] = $function;
            }

        }

        // load Config/*.ini and so on
        $config_dir = "../Workers/Config";
        $config_file_array = glob($config_dir.'/*.ini');
//        var_dump($config_file_array);
        foreach($config_file_array as $config)
        {
            if(file_exists($config)) {
                $configArray = parse_ini_file($config, true);
                // get Worker name
                $file = substr(basename($config), 0, -4);
                $this->config['functions'][$file] = $configArray[ini_get('yaf.environ')];
                $this->config['services'][$configArray[ini_get('yaf.environ')]['service']][] = $file;
                unset($configArray);
                $this->log("SMS.INI IS LOADED!!!!!!!", WorkerManager::LOG_LEVEL_DEBUG);
//                var_dump($configArray);
            }
        }
//        var_dump($this->config);
    }

    /**
     * Helper function to load and filter worker files
     *
     * return @void
     */
    protected function load_workers() {

        $this->functions = array();

        $dirs = is_array($this->worker_dir) ? $this->worker_dir : explode(",", $this->worker_dir);

        foreach($dirs as $dir){

            $this->log("Loading workers in ".$dir);

            $worker_files = glob($dir."/*.php");
            $real_worker_files = array();
            foreach ($worker_files as $file) {
                $workerName = substr(basename($file), 0, -4);
                if (isset($this->config['services'][$workerName]) && count($this->config['services'][$workerName]) > 1) {
                    foreach ($this->config['services'][$workerName] as $function) {
                        $real_worker_files[$function] = $file;
                    }
                } else {
                    $real_worker_files[$workerName] = $file;
                }
            }
            unset($worker_files);
            $worker_files = $real_worker_files;

            if (!empty($worker_files)) {

                foreach($worker_files as $function => $file){
                    /**
                     * include workers
                     */
                    if (!empty($this->config['include'])) {
                        if (!in_array($function, $this->config['include'])) {
                            continue;
                        }
                    }

                    /**
                     * exclude workers
                     */
                    if (in_array($function, $this->config['exclude'])) {
                        continue;
                    }

                    if(!isset($this->functions[$function])){
                        $this->functions[$function] = array('name' => $function);
                    }

                    if(!empty($this->config['functions'][$function]['dedicated_only'])){

                        if(empty($this->config['functions'][$function]['dedicated_count'])){
                            $this->log("Invalid configuration for dedicated_count for function $function.", WorkerManager::LOG_LEVEL_PROC_INFO);
                            exit();
                        }

                        $this->functions[$function]['dedicated_only'] = true;
                        $this->functions[$function]["count"] = $this->config['functions'][$function]['dedicated_count'];

                    } else {
                        $min_count = max($this->do_all_count, 0);
                        if(!empty($this->config['functions'][$function]['count'])){
                            $min_count = max($this->config['functions'][$function]['count'], $this->do_all_count);
                        }

                        if(!empty($this->config['functions'][$function]['dedicated_count'])){
                            $ded_count = $this->do_all_count + $this->config['functions'][$function]['dedicated_count'];
                        } elseif(!empty($this->config["dedicated_count"])){
                            $ded_count = $this->do_all_count + $this->config["dedicated_count"];
                        } else {
                            $ded_count = $min_count;
                        }

                        $this->functions[$function]["count"] = max($min_count, $ded_count);

                    }

                    $this->functions[$function]['path'] = $file;
                    $this->functions[$function]['class_name'] = $this->prefix . $function;

                    /**
                     * Note about priority. This exploits an undocumented feature
                     * of the worker daemon. This will only work as long as the
                     * current behavior of the daemon remains the same. It is not
                     * a defined part fo the protocol.
                     */
                    if(!empty($this->config['functions'][$function]['priority'])){
                        $priority = max(min(
                            $this->config['functions'][$function]['priority'],
                            self::MAX_PRIORITY), self::MIN_PRIORITY);
                    } else {
                        $priority = 0;
                    }

                    $this->functions[$function]['priority'] = $priority;

                }
            }
        }
    }

    /**
     * Forks the process and runs the given method. The parent then waits
     * for the child process to signal back that it can continue
     *
     * @param   string  $method  Class method to run after forking
     *
     */
    protected function fork_me($method){
        $this->wait_for_signal = true;
        $pid = pcntl_fork();
        switch($pid) {
            case 0:
                $this->isparent = false;
                $this->parent_pid = $this->pid;
                $this->pid = getmypid();
                $this->$method();
                break;
            case -1:
                $this->log("Failed to fork");
                $this->stop_work = true;
                break;
            default:
                $this->log("Helper forked with pid $pid", WorkerManager::LOG_LEVEL_PROC_INFO);
                $this->helper_pid = $pid;
                while($this->wait_for_signal && !$this->stop_work) {
                    usleep(5000);
                    pcntl_waitpid($pid, $status, WNOHANG);

                    if (pcntl_wifexited($status) && $status) {
                        $this->log("Helper child exited with non-zero exit code $status.");
                        exit(1);
                    }

                }
                break;
        }
    }


    /**
     * Forked method that validates the worker code and checks it if desired
     *
     */
    protected function validate_workers(){

        $this->load_workers();

        if(empty($this->functions)){
            $this->log("No workers found");
            posix_kill($this->parent_pid, SIGUSR1);
            exit();
        }

        $this->validate_lib_workers();

        /**
         * Since we got here, all must be ok, send a CONTINUE
         */
        $this->log("Helper is running. Sending continue to $this->parent_pid.", WorkerManager::LOG_LEVEL_DEBUG);
        posix_kill($this->parent_pid, SIGCONT);

        if($this->check_code){
            $this->log("Running loop to check for new code", self::LOG_LEVEL_DEBUG);
            $last_check_time = 0;
            while(1) {
                $max_time = 0;
                foreach($this->functions as $name => $func){
                    clearstatcache();
                    $mtime = filemtime($func['path']);
                    $max_time = max($max_time, $mtime);
                    $this->log("{$func['path']} - $mtime $last_check_time", self::LOG_LEVEL_CRAZY);
                    if($last_check_time!=0 && $mtime > $last_check_time){
                        $this->log("New code found. Sending SIGHUP", self::LOG_LEVEL_PROC_INFO);
                        posix_kill($this->parent_pid, SIGHUP);
                        break;
                    }
                }
                $last_check_time = $max_time;
                sleep(5);
            }
        } else {
            exit();
        }

    }

    /**
     * Bootstap a set of workers and any vars that need to be set
     *
     */
    protected function bootstrap() {

        $function_count = array();

        /**
         * If we have "do_all" workers, start them first
         * do_all workers register all functions
         */
        if(!empty($this->do_all_count) && is_int($this->do_all_count)){

            for($x=0;$x<$this->do_all_count;$x++){
                $this->start_worker();
            }

            foreach($this->functions as $worker => $settings){
                if(empty($settings["dedicated_only"])){
                    $function_count[$worker] = $this->do_all_count;
                }
            }

        }

        /**
         * Next we loop the workers and ensure we have enough running
         * for each worker
         */
        foreach($this->functions as $worker=>$config) {

            /**
             * If we don't have do_all workers, this won't be set, so we need
             * to init it here
             */
            if(empty($function_count[$worker])){
                $function_count[$worker] = 0;
            }

            while($function_count[$worker] < $config["count"]){
                $this->start_worker($worker);
                $function_count[$worker]++;;
            }

            /**
             * php will eat up your cpu if you don't have this
             */
            usleep(50000);

        }

        /**
         * Set the last code check time to now since we just loaded all the code
         */
        $this->last_check_time = time();

    }

    protected function start_worker($worker="all") {

        static $all_workers;

        if(is_array($worker)){

            $worker_list = $worker;

        } elseif($worker == "all"){

            if(is_null($all_workers)){
                $all_workers = array();
                foreach($this->functions as $func=>$settings){
                    if(empty($settings["dedicated_only"])){
                        $all_workers[] = $func;
                    }
                }
            }
            $worker_list = $all_workers;
        } else {
            $worker_list = array($worker);
        }

        $timeouts = array();
        $default_timeout = ((isset($this->config['timeout'])) ?
            (int) $this->config['timeout'] : null);

        // build up the list of worker timeouts
        foreach ($worker_list as $worker_name) {
            $timeouts[$worker_name] = ((isset($this->config['functions'][$worker_name]['timeout'])) ?
                (int) $this->config['functions'][$worker_name]['timeout'] : $default_timeout);
        }

        foreach ($worker_list as $worker_name) {
            $services[$worker_name] = ((isset($this->config['functions'][$worker_name]['service'])) ?
                $this->config['functions'][$worker_name]['service'] : null);
        }

        $pid = pcntl_fork();

        switch($pid) {

            case 0:
                $this->isparent = false;

                $this->register_ticks(false);

                $this->pid = getmypid();

                if(count($worker_list) > 1){

                    // shuffle the list to avoid queue preference
                    shuffle($worker_list);

                    // sort the shuffled array by priority
                    uasort($worker_list, array($this, "sort_priority"));
                }

                if($this->worker_restart_splay > 0){
                    mt_srand($this->pid); // Since all child threads use the same seed, we need to reseed with the pid so that we get a new "random" number.
                    $splay = mt_rand(0, $this->worker_restart_splay);
                    $this->max_run_time = $this->config['max_worker_lifetime'] + $splay;
                    $this->log("Adjusted max run time to {$this->max_run_time} seconds (max_worker_lifetime:{$this->config['max_worker_lifetime']} + splay:{$splay})", WorkerManager::LOG_LEVEL_DEBUG);
                }

                $this->start_lib_worker($worker_list, $timeouts, $services);

                $this->log("Child exiting", WorkerManager::LOG_LEVEL_WORKER_INFO);

                exit();

                break;

            case -1:

                $this->log("Could not fork");
                $this->stop_work = true;
                $this->stop_children();
                break;

            default:

                // parent
                $this->log("Started child $pid (".implode(",", $worker_list).")", WorkerManager::LOG_LEVEL_PROC_INFO);
                $this->children[$pid] = array(
                    "job" => $worker_list,
                    "start_time" => time(),
                );
        }

    }

    /**
     * Sorts the function list by priority
     */
    private function sort_priority($a, $b) {
        $func_a = $this->functions[$a];
        $func_b = $this->functions[$b];

        if(!isset($func_a["priority"])){
            $func_a["priority"] = 0;
        }
        if(!isset($func_b["priority"])){
            $func_b["priority"] = 0;
        }
        if ($func_a["priority"] == $func_b["priority"]) {
            return 0;
        }
        return ($func_a["priority"] > $func_b["priority"]) ? -1 : 1;
    }

    /**
     * Stops all running children
     */
    protected function stop_children($signal=SIGTERM) {
        $this->log("Stopping children", WorkerManager::LOG_LEVEL_PROC_INFO);

        foreach($this->children as $pid=>$child){
            $this->log("Stopping child $pid (".implode(",", $child['job']).")", WorkerManager::LOG_LEVEL_PROC_INFO);
            posix_kill($pid, $signal);
        }

    }

    /**
     * Registers the process signal listeners
     */
    protected function register_ticks($parent=true) {

        if($parent){
            $this->log("Registering signals for parent", WorkerManager::LOG_LEVEL_DEBUG);
            pcntl_signal(SIGTERM, array($this, "signal"));
            pcntl_signal(SIGINT,  array($this, "signal"));
            pcntl_signal(SIGUSR1,  array($this, "signal"));
            pcntl_signal(SIGUSR2,  array($this, "signal"));
            pcntl_signal(SIGCONT,  array($this, "signal"));
            pcntl_signal(SIGHUP,  array($this, "signal"));
        } else {
            $this->log("Registering signals for child", WorkerManager::LOG_LEVEL_DEBUG);
            $res = pcntl_signal(SIGTERM, array($this, "signal"));
            if(!$res){
                exit();
            }
        }
    }

    /**
     * Handles signals
     */
    public function signal($signo) {

        static $term_count = 0;

        if(!$this->isparent){

            $this->stop_work = true;

        } else {

            switch ($signo) {
                case SIGUSR1:
                    $this->show_help("No worker files could be found");
                    break;
                case SIGUSR2:
                    $this->show_help("Error validating worker functions");
                    break;
                case SIGCONT:
                    $this->wait_for_signal = false;
                    break;
                case SIGINT:
                case SIGTERM:
                    $this->log("Shutting down...");
                    $this->stop_work = true;
                    $this->stop_time = time();
                    $term_count++;
                    if($term_count < 5){
                        $this->stop_children();
                    } else {
                        $this->stop_children(SIGKILL);
                    }
                    break;
                case SIGHUP:
                    $this->log("Restarting children", WorkerManager::LOG_LEVEL_PROC_INFO);
                    if ($this->log_file) {
                        $this->open_log_file();
                    }
                    $this->stop_children();
                    break;
                default:
                    // handle all other signals
            }
        }

    }

    /**
     * Opens the log file. If already open, closes it first.
     */
    protected function open_log_file() {

        if($this->log_file){

            if($this->log_file_handle){
                fclose($this->log_file_handle);
            }

            $this->log_file_handle = @fopen($this->config['log_file'], "a");
            if(!$this->log_file_handle){
                $this->show_help("Could not open log file {$this->config['log_file']}");
            }
        }

    }

    /**
     * Logs data to disk or stdout
     */
    protected function log($message, $level=WorkerManager::LOG_LEVEL_INFO) {

        static $init = false;

        if($level > $this->verbose) return;

        if ($this->log_syslog) {
            $this->syslog($message, $level);
            return;
        }

        if(!$init){
            $init = true;

            if($this->log_file_handle){
                fwrite($this->log_file_handle, "Date                         PID   Type   Message\n");
            } else {
                echo "PID   Type   Message\n";
            }

        }

        $label = "";

        switch($level) {
            case WorkerManager::LOG_LEVEL_INFO;
                $label = "INFO  ";
                break;
            case WorkerManager::LOG_LEVEL_PROC_INFO:
                $label = "PROC  ";
                break;
            case WorkerManager::LOG_LEVEL_WORKER_INFO:
                $label = "WORKER";
                break;
            case WorkerManager::LOG_LEVEL_DEBUG:
                $label = "DEBUG ";
                break;
            case WorkerManager::LOG_LEVEL_CRAZY:
                $label = "CRAZY ";
                break;
        }


        $log_pid = str_pad($this->pid, 5, " ", STR_PAD_LEFT);

        if($this->log_file_handle){
            list($ts, $ms) = explode(".", sprintf("%f", microtime(true)));
            $ds = date("Y-m-d H:i:s").".".str_pad($ms, 6, 0);
            $prefix = "[$ds] $log_pid $label";
            fwrite($this->log_file_handle, $prefix." ".str_replace("\n", "\n$prefix ", trim($message))."\n");
        } else {
            $prefix = "$log_pid $label";
            echo $prefix." ".str_replace("\n", "\n$prefix ", trim($message))."\n";
        }

    }

    /**
     * Logs data to syslog
     */
    protected function syslog($message, $level) {
        switch($level) {
            case WorkerManager::LOG_LEVEL_INFO;
            case WorkerManager::LOG_LEVEL_PROC_INFO:
            case WorkerManager::LOG_LEVEL_WORKER_INFO:
            default:
                $priority = LOG_INFO;
                break;
            case WorkerManager::LOG_LEVEL_DEBUG:
                $priority = LOG_DEBUG;
                break;
        }

        if (!syslog($priority, $message)) {
            echo "Unable to write to syslog\n";
        }
    }

    /**
     * Shows the scripts help info with optional error message
     */
    protected function show_help($msg = "") {
        if($msg){
            echo "ERROR:\n";
            echo "  ".wordwrap($msg, 72, "\n  ")."\n\n";
        }
        echo "Worker worker manager script\n\n";
        echo "USAGE:\n";
        echo "  # ".basename(__FILE__)." -h | -c CONFIG [-v] [-l LOG_FILE] [-d] [-v] [-a] [-P PID_FILE]\n\n";
        echo "OPTIONS:\n";
        echo "  -a             Automatically check for new worker code\n";
        echo "  -c CONFIG      Worker configuration file\n";
        echo "  -d             Daemon, detach and run in the background\n";
        echo "  -D NUMBER      Start NUMBER workers that do all jobs\n";
        echo "  -h HOST:PORT   Connect to HOST and PORT\n";
        echo "  -H             Shows this help\n";
        echo "  -l LOG_FILE    Log output to LOG_FILE or use keyword 'syslog' for syslog support\n";
        echo "  -p PREFIX      Optional prefix for functions/classes of PECL workers. PEAR requires a constant be defined in code.\n";
        echo "  -P PID_FILE    File to write process ID out to\n";
        echo "  -u USERNAME    Run workers as USERNAME\n";
        echo "  -v             Increase verbosity level by one\n";
        echo "  -w DIR         Directory where workers are located, defaults to ./workers. If you are using PECL, you can provide multiple directories separated by a comma.\n";
        echo "  -r NUMBER      Maximum job iterations per worker\n";
        echo "  -t SECONDS     Maximum number of seconds gearmand server should wait for a worker to complete work before timing out and reissuing work to another worker.\n";
        echo "  -x SECONDS     Maximum seconds for a worker to live\n";
        echo "  -Z             Parse the command line and config file then dump it to the screen and exit.\n";
        echo "\n";
        exit();
    }

}