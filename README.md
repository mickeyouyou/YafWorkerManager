# YafWorkerManager
YafWorkerManager Based on Yaf


基于`Beanstalkd`消息队列和Yaf框架的Worker 服务管理，是类GearMan，基于服务配置方式的另一种实现。

本服务依赖`Beanstalkd`消息队列, 安装`Beanstalkd`:

源码下载：`https://github.com/kr/beanstalkd/archive/v1.10.tar.gz`

#### 启动Beanstalkd
```
beanstalkd -l 0.0.0.0 -p 11300 -f 300
```

##### 启动worker服务
```
cd Bin
worker-beanstalk-manager start
```


#### 服务关闭

```
cd Bin
worker-beanstalk-manager stop
```

比如为短信发送服务开启50个进程的配置：
```
; We are guaranteed 1 workers that can do job Sms
count = 50
```

#### 日志监控

默认的日志会放在脚本`worker-beanstalk-manager`中配置的目录中：

```
PIDDIR=/data/logs/beanstalk
PIDFILE=${PIDDIR}/manager.pid
LOGFILE=${PIDDIR}/beanstalk-manager.log
```
