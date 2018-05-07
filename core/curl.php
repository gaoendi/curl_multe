<?php
/**
* ChromeMozilla/5.0 (Windows NT 6.1) AppleWebKit/536.11 (KHTML, like Gecko) Chrome/20.0.1132.47 Safari/536.11
* IE6 Mozilla/5.0 (Windows NT 6.1; rv:9.0.1) Gecko/20100101 Firefox/9.0.1
* FFMozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1; SV1; CIBA; .NET CLR 2.0.50727)
*/
class CURL{

		const ITEM_URL=0;
		const ITEM_P=1;
		const ITEM_F=2;
		const ITEM_TRYED=3;
		const ITEM_FP=4;
		const ITEM_P_OPT=5;
		//线程的限制
		public $limit=30;
		//try time(s) before curl failed
		public $maxTry=2;
		//用户定义的选择
		public $opt=array();
		//缓存选项
		public $cache=array('on'=>false,'dir'=>null,'expire'=>86400);
		//任务回调,if taskpool is empty,this callback will be called,you can call CUrl::add() in callback
		public $task=null;
		//the real multi-thread num 真正的多线程数
		private $activeNum=0;
		//队列中已完成的任务
		private $queueNum=0;
		//finished task number,include failed task and cache
		private $finishNum=0;
		//The number of cache hit
		private $cacheNum=0;
		//completely failed task number
		private $failedNum=0;
		//已经添加任务数
		private $taskNum=0;
		//保存所有添加的任务
		private $taskPool=array();
		//running task(s)
		private $taskRunning=array();
		//failed task need to retry
		private $taskFailed=array();
		//total downloaded size,byte
		private $traffic=0;
		//handle of multi-thread curl
		private $mh=null;
		//time multi-thread start
		private $startTime=null;
		/**
		* running infomation
		* 运行信息
		*/
		function status($debug=false){
			if($debug==='reset'){
				$this->taskNum=0;
				$this->finishNum=0;
				$this->cacheNum=0;
				$this->activeNum=0;
				$this->taskRunning=array();
				$this->queueNum=0;
				$this->failedNum=0;
				$this->traffic=0;
				$this->taskPool=array();
				$last=0;
				$strlen=0;
				return;
			}
			if($debug){
				$s="finish:".($this->finishNum).'('.$this->cacheNum.')';
				$s.="task:".$this->taskNum;
				$s.="active:".$this->activeNum;
				$s.="running:".count($this->taskRunning);
				$s.="queue:".$this->queueNum;
				$s.="failed:".$this->failedNum;
				$s.="taskPool:".count($this->taskPool);
				echo $s."/n";
			}else{
			static $last=0;
			static $strlen=0;
			$now=time();
			//update status every 1 minute or all task finished
			$msg='';
			if($now>$last or ($this->finishNum==$this->taskNum)){
				$last=$now;
				$timeSpent=$now-$this->startTime;
				if($timeSpent==0)
				$timeSpent=1;
				//percent
				$s=sprintf('%-.2f%%',round($this->finishNum/$this->taskNum,4)*100);
				//num
				$s.=sprintf('%'.strlen($this->finishNum).'d/%-'.strlen($this->taskNum).'d(%-'.strlen($this->cacheNum).'d)',$this->finishNum,$this->taskNum,$this->cacheNum);
				//speed
				$speed=($this->finishNum-$this->cacheNum)/$timeSpent;
				$s.=sprintf('%-d',$speed).'/s';
				//net speed
				$suffix='KB';
				$netSpeed=$this->traffic/1024/$timeSpent;
				if($netSpeed>1024){
					$suffix='MB';
					$netSpeed/=1024;
				}
				$s.=sprintf('%-.2f'.$suffix.'/s',$netSpeed);
				//total size
				$suffix='KB';
				$size=$this->traffic/1024;
				if($size>1024){
					$suffix='MB';
					$size/=1024;
					if($size>1024){
						$suffix='GB';
						$size/=1024;
					}
				}
				$s.=sprintf('%-.2f'.$suffix,$size);
				//estimated time of arrival
				if($speed==0){
					$str='--';
				}else{
					$eta=($this->taskNum-$this->finishNum)/$speed;
					$str=ceil($eta).'s';
					if($eta>3600){
					$str=ceil($eta/3600).'h'.ceil(($eta%3600)/60).'m';
				}elseif($eta>60){
					$str=ceil($eta/60).'m'.($eta%60).'s';
				}
			}
			$s.='ETA '.$str;
			$msg=$s;
			}
			return $msg;
			}
		}
		/**
		* read interface
		*/
		function __get($name){
			return $this->$name;
		}
		/**
		* single thread
		*
		* @param mixed $url
		* @return mixed curl_exec() result
		*/
		function read($url){
			$r=array();
			$ch=$this->init($url);
			$content=curl_exec($ch);
			if(curl_errno($ch)===0){
				$r['info']=curl_getinfo($ch);
				$r['content']=$content;
			}else{
				debug_print('error: code '.curl_errno($ch).", ".curl_error($ch),E_USER_WARNING);
			}
			return $r;
		}
		/**
		* add a task to taskPool
		*
		* @param array $url $url[0] is url,$url[1] is file path if isset,$url[2] is curl option
		* @param array $p 成功时回调,$p[0]是回调的函数,$p[1]是回调函数的参数
		* @param array $f 失败时回调,$f[0]是回调的函数,$f[1]是回调函数的参数
		*/
		function add($url=array(),$p=array(),$f=array()){
			//check
			if(!is_array($url) or empty($url[0])){
				var_dump($url);
				debug_print('url is invalid',E_USER_ERROR);
			}
			if(!is_array($p) or !is_array($f))
				debug_print('callback is not array',E_USER_ERROR);
			if(!isset($p[0]))
				debug_print('process callback is not set',E_USER_ERROR);
			if((isset($p[1]) and !is_array($p[1])) or (isset($f[1]) and !is_array($f[1]))){
				debug_print('callback function parameter must be an array',E_USER_ERROR);
			}
			//fix
			if(empty($url[1]))
				$url[1]=null;
			if(empty($url[2]))
				$url[2]=null;
			if(!isset($p[1]))
				$p[1]=array();
			if(isset($f[0]) and !isset($f[1]))
				$f[1]=array();
				$task=array();
				$task[self::ITEM_URL]=$url;
				$task[self::ITEM_P]=$p;
				$task[self::ITEM_P_OPT]=$url[2];
				$task[self::ITEM_F]=$f;
				$task[self::ITEM_TRYED]=0; //try times befroe complete failure
				$task[self::ITEM_FP]=null; //file handler for file download
				$this->taskPool[]=$task;
				$this->taskNum++;
			}
		/**
		* Perform the actual task(s).
		* 执行实际任务
		*/
		function go(){
			static $running=false;
			if($running)
				debug_print('CURL can only run one instance',E_USER_ERROR);
				$this->mh=curl_multi_init();
			for($i=0;$i<$this->limit;$i++)
				$this->addTask();
				$this->startTime=time();
				$running=true;
			do{
				$this->exec();
				//主要用于阻断curl_multi_select
				curl_multi_select($this->mh);
				while($curlInfo = curl_multi_info_read($this->mh,$this->queueNum)){
					$ch=$curlInfo['handle'];
					$info=curl_getinfo($ch);
					$this->traffic+=$info['size_download'];
					$k=(int)$ch;
					$task=$this->taskRunning[$k];
					if(empty($task)){
						debug_print("can't get running task",E_USER_WARNING);
					}
					$callFail=false;
					if($curlInfo['result']==CURLE_OK){
						if(isset($task[self::ITEM_P])){
							array_unshift($task[self::ITEM_P][1],array(
							'info'=>$info,
							'content'=>curl_multi_getcontent($ch),
							));
						}
					}else{
						if($task[self::ITEM_TRYED] >= $this->maxTry){
							$msg='curl error '.$curlInfo['result'].', '.curl_error($ch).', '.$info['url'];
							if(isset($task[self::ITEM_F][0])){
								array_unshift($task[self::ITEM_F][1],$msg);
								$callFail=true;
							}else{
								echo $msg."/n";
							}
							$this->failedNum++;
						}else{
							$task[self::ITEM_TRYED]++;
							$this->taskFailed[]=$task;
							$this->taskNum++;
						}
					}
					curl_multi_remove_handle($this->mh,$ch);
					curl_close($ch);
					unset($this->taskRunning[$k]);
					$this->finishNum++;
					if($curlInfo['result']==CURLE_OK){
						call_user_func_array($task[self::ITEM_P][0],$task[self::ITEM_P][1]);
					}elseif($callFail){
						call_user_func_array($task[self::ITEM_F][0],$task[self::ITEM_F][1]);
					}
					$this->addTask();
					//so skilful,if $this->queueNum grow very fast there will be no efficiency lost,because outer $this->exec() won't be executed.
					$this->exec();
				}
			}while($this->activeNum || $this->queueNum || !empty($this->taskFailed) || !empty($this->taskRunning) || !empty($this->taskPool));
				unset($this->startTime);
				curl_multi_close($this->mh);
				$running=false;
			}
			/**
			* curl_multi_exec()
			* 运行当前 cURL 句柄的子连接
			*/
			private function exec(){
				while(curl_multi_exec($this->mh, $this->activeNum)===CURLM_CALL_MULTI_PERFORM){}
			}
			/**
			* add a task to curl
			* 添加一个任务到curl
			*/
			private function addTask(){
				$c=$this->limit-count($this->taskRunning);
				while($c>0){
					$task=array();
					//search failed first
					if(!empty($this->taskFailed)){
						$task=array_pop($this->taskFailed);
					}else{
						if(!empty($this->taskPool))
							$task=array_pop($this->taskPool);
						}
						if(!empty($task)){
						$ch = '';
						$ch=$this->init($task[self::ITEM_URL][0]);
						if(is_resource($ch)){
							//单curl任务选项
							if(isset($task[self::ITEM_P_OPT])){
								foreach($task[self::ITEM_P_OPT] as $k=>$v)
									curl_setopt($ch,$k,$v);
									curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // 跳过证书检查
									curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, true);// 从证书中检查SSL加密算法是否存在
							}
							curl_multi_add_handle($this->mh,$ch);
							$this->taskRunning[(int)$ch]=$task;
						}else{
							debug_print('$ch is not resource,curl_init failed.',E_USER_WARNING);
						}
					}
					$c--;
				}
			}
			/**
			* set or get file cache
			* 设置或获取文件缓存
			* @param mixed $key
			* @param mixed $content
			* @return return content or false if read,true or false if write
			*/
			private function cache($url,$content=null){
				$key=md5($url);
				if(!isset($this->cache['dir']))
					debug_print('Cache dir is not defined',E_USER_ERROR);
					$dir=$this->cache['dir'].DIRECTORY_SEPARATOR.substr($key,0,3);
					$file=$dir.DIRECTORY_SEPARATOR.substr($key,3);
				if(!isset($content)){
					if(file_exists($file)){
						if((time()-filemtime($file)) < $this->cache['expire']){
							return unserialize(file_get_contents($file));
						}else{
							unlink($file);
						}
					}
				}else{
					$r=false;
					//检查主目录是否存在
					if(!is_dir($this->cache['dir'])){
						debug_print("Cache dir doesn't exists",E_USER_ERROR);
					}else{
						$dir=dirname($file);
						if(!file_exists($dir) and !mkdir($dir,0777))
							debug_print("Create dir failed",E_USER_WARNING);
							$content=serialize($content);
							if(file_put_contents($file,$content,LOCK_EX))
								$r=true;
							else
								debug_print('Write cache file failed',E_USER_WARNING);
					}
					return $r;
				}
			}
			private function init($url){
				$ch=curl_init();
				$opt=array();
				$opt[CURLOPT_URL]=$url;
				$opt[CURLOPT_HEADER]=false;
				$opt[CURLOPT_CONNECTTIMEOUT]=15;
				$opt[CURLOPT_TIMEOUT]=300;
				// $opt[CURLOPT_AUTOREFERER]=true;
				// $opt[CURLOPT_USERAGENT]='Mozilla/5.0 (Windows NT 6.1) AppleWebKit/536.11 (KHTML, like Gecko) Chrome/20.0.1132.47 Safari/536.11';
				$opt[CURLOPT_RETURNTRANSFER]=true;
				// $opt[CURLOPT_FOLLOWLOCATION]=true;
				$opt[CURLOPT_MAXREDIRS]=10;
				//user defined opt
				if(!empty($this->opt))
					foreach($this->opt as $k=>$v)
						$opt[$k]=$v;
						curl_setopt_array($ch,$opt);
					return $ch;
				}
			}