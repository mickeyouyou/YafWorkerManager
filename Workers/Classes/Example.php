<?php
 /**
 * 短信发送消息队列
 *
 * @author fengzbao@qq.com 
 * @copyright Copyright (c) Beijing Jinritemai Technology Co.,Ltd.
 * @version $Id:1.0.0, Example.php, 2015-04-30 15:36 created (updated)$
 */


use Yaf\Application;
use Yaf\Exception;
use Yaf\Registry;
use Yaf\Request\Simple;

class WorkerExample
{
    /**
     * yaf应用实例
     *
     * @var Application $app
     */
    public $app;


    /**
     * 是否完成引导
     *
     * @var boolean $bootstrap
     */
    public $bootstrap = false;

    /**
     * 当前job
     *
     * @var Job $job;
     */
    public $job;

    /**
     * 当前队列操作句柄
     *
     * @var object $tube
     */
    public $tube;

    /**
     * 构造方法
     *
     * 初始化数据
     */
    public function __construct()
    {
        try {
            $this->app = new Application(APP_INI);
            $this->app->bootstrap();
        } catch(Exception $e) {
            $this->logException($e);
            exit(1);
        }
    }

    /**
     * run worker处理方法 
     * 
     * @param string $payload 队列数据 
     * @param string $log 
     * @param string $job 任务对象
     * @param string $tube 管道对象
     * @access public
     * @return void
     */
    public function run($payload, &$log, $job, $tube)
    {
        $this->job  = $job;
        $this->tube = $tube;

        try {

            // 业务逻辑代码


            // 涉及到的请求分发
            $sendRequest = new Simple('method', 'module', 'controller', 'action', $payload);
            $result = $this->app->getDispatcher()->dispatch($sendRequest);
            $result = json_decode($result->getBody(), true);

        } catch (Exception $e)
        {
            return 'error';
        }
    }
}
