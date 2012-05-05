<?php
error_reporting(E_ALL && ~E_NOTICE);

class runtime {
	var $StartTime = 0;
	var $StopTime = 0;
	
	function get_microtime() {
		list($usec, $sec) = explode(' ', microtime());
		return ((float) $usec + (float) $sec);
	}
	
	function start() {
		$this->StartTime = $this->get_microtime();
	}
	
	function stop() {
		$this->StopTime = $this->get_microtime();
	}
	
	function spent() {
		return round(($this->StopTime - $this->StartTime) * 1000, 1);
	}
}

class CacheList {
	
	public $host = "127.0.0.1";
	public $port = 6379;	
	public $redis;	
	public $item = array("P" => NULL, "V" => NULL, "E"=>0, "N" => NULL);	
	public $name = NULL;
	public $head = NULL;
	public $primaryKey = NULL;
	public $notify = NULL;
	public $notifyTo = NULL;
	public $expire = NULL;	
	public $list = NULL;
	public $items = NULL;
	
	public function __construct($name, $primaryKey) {
		if (!$name || !$primaryKey) $this->warn("Could net create list without listname.");
		
		$this->name = $name;
		$this->primaryKey = $primaryKey;		
		$this->items = $this->name . ":Items";
		$this->list = $this->name . ":List";
		$this->head = $this->name . ":Head";
		$this->tail = $this->name . ":Tail";
		$this->expire = $this->name . ":Expire";
		$this->notify = $this->name . ":Notify";
		$this->notifyTo = $this->name . ":NotifyTo";
		
		$this->redis = $this->getInstance();
	}
	
	private function getInstance() {
		$r = new Redis();
		$r->connect("127.0.0.1", 6379);
		return $r;
	}
	
	/**
	 * 
	 * 增加数据到链表
	 * @param array $list 数据
	 * @param int $expire 链表过期时间 
	 */
	public function addList($list, $expire) {
		$count = count($list);
		if (!isset($list[0][$this->primaryKey])) $this->warn("Could ont find " . $this->primaryKey . " in list.");
		
		if ($expire > 0) $this->setExpire($expire);
		for ($i = 0; $i < $count; $i++) {
			$this->initItem();
			
			if ($i != 0) $this->item['P'] = $list[$i - 1][$this->primaryKey];
			$this->item['V'] = $list[$i][$this->primaryKey];
			$this->item['E'] = $list[$i]['expire'];
			if ($list[$i + 1]) $this->item['N'] = $list[$i + 1][$this->primaryKey];
			
			if ($i == 0) $this->setHead($this->item);
			
			$this->setItem($this->item);
			$this->setItemData($this->item, $list[$i]);
		}
	}
	
	/**
	 * 
	 * 获取链表数据 
	 * @param int $start
	 * @param int $limit
	 */
	public function getList($start, $limit) {
		if (!$this->checkExpire()) return false;
		if (!$this->checkNotify()) return false;
		$i = 0;
		$list = array();
		$item = $this->getHead();
		
		if ($item == NULL) return false;
		while ($item['N'] != NULL) {
			$this->checkItem($item);
			$list[] = $this->getItemData($item['V']);
			if ($i == $limit - 1) break;
			if ($item['N'] == NULL) break;
			$item = $this->next($item);
			$i++;
		}
		return $list;
	}
	
	/**
	 * 
	 * 检查链表是否过期
	 */
	public function checkExpire(){
		if (($this->getExpire() > 0) && 
			($this->getExpire() <= $this->getTime())) {
			$this->delList();
			return false;
		};
		return true;
	}
	
	/**
	 * 
	 * 检查通知
	 */
	public function checkNotify(){
		if ($this->getNotify() == 1) {
			$this->delList();
			return false;
		}
		return true;
	}
	
	/**
	 * 
	 * 检查元素
	 * @param array $item
	 */
	public function checkItem($item) {
		if ($item['E']  > 0){
			if ($item['E'] <= $this->getTime()) {
				$this->delItem($item['V']);
			}
		}
	}
	
	/**
	 * 
	 * 当前时间 
	 */
	private function getTime() {
		return time();
	}
	
	/**
	 * 
	 * 设置元数据原子数据 
	 * @param array $item
	 * @param array $data
	 */
	public function setItemData($item, $data) {
		$this->redis->hSet($this->items, $item['V'], json_encode($data));
	}
	
	/**
	 * 
	 * 获取一个元素原子信息
	 * @param int $value
	 */
	public function getItemData($value) {
		$data = $this->redis->hGet($this->items, $value);
		return json_decode($data, true);
	}
	
	/**
	 * 
	 * 更新／增加一个元素
	 * @param array $item
	 */
	public function setItem($item) {
		if ($item['E'] > 0) $item['E'] += $this->getTime();
		$this->redis->hSet($this->list, $item['V'], json_encode($item));
	}
	
	/**
	 * 
	 * 获取一个元素
	 * @param int $value
	 */
	public function getItem($value) {
		$data = $this->redis->hGet($this->list, $value);
		return json_decode($data, true);
	}
	
	/**
	 * 
	 * 删除一个索引
	 * @param int $value
	 */
	public function delItem($value) {
		$item = $this->getItem($value);
		if (!$item) return false;
		//如果是表头
		if ($item['P'] == NULL) {
			$next = $this->next($item);
			$next['P'] = NULL;
			$this->setHead($next);
			$this->redis->delete($item);
		}
		
		//如果是表结尾
		if ($item['N'] == NULL) {
			$pre = $this->pre($item);
			$pre['N'] = NULL;
			$this->addItem($pre);
			$this->redis->delete($item);
		}
		
		$pre = $this->pre($item);
		$next = $this->next($item);
		
		$pre['N'] = $next['V'];
		$next['P'] = $pre['V'];
		
		if ($pre['P'] == NULL) $this->setHead($pre);
		$this->setItem($pre);
		$this->setItem($next);

		$this->redis->hDel($this->list, $item['V']);
		$this->redis->hDel($this->items, $item['V']);
	}
	
	/**
	 * 
	 * 获取上一个元素
	 * @param array $item
	 */
	public function pre($item){
		return $this->getItem($item['P']);
	}
	
	/**
	 * 
	 * 获取下一个元素
	 * @param array $item
	 */
	public function next($item) {
		return $this->getItem($item['N']);
	}
	
	/**
	 * 
	 * 初始化一个索引结构
	 */
	public function initItem() {
		$this->item['P'] = NULL;
		$this->item['V'] = NULL;
		$this->item['E'] = 0;
		$this->item['N'] = NULL;
	}
	
	/**
	 * 
	 * 删除链表
	 */
	public function delList() {
		$this->redis->delete($this->head);
		$this->redis->delete($this->list);
		$this->redis->delete($this->items);
		$this->redis->delete($this->notify);
	}
	
	/**
	 * 
	 * 向通知对象发送通知
	 */
	public function notify(){
		$notifyTo = $this->getNotifyTo();
		foreach ($notifyTo as $key=>$value) {
			$this->set($key . ":Notify", $value);
		}
	}
	
	/**
	 * 
	 * 设置链表头
	 * @param string $item 单个索引元素
	 */
	private function setHead($item) {
		$this->redis->set($this->head, json_encode($item));
	}
	
	/**
	 * 
	 * 获取链表头
	 */
	private function getHead() {
		return json_decode($this->redis->get($this->head), true);
	}
	
	/**
	 * 
	 * 增加通知链表
	 * @param string $name 链表名称
	 */
	private function addNotifyTo($name) {
		$this->redis->hMset($this->notifyTo, array($name=>0));
	}
	
	/**
	 * 
	 * 获取通知对象－－链表 
	 */
	private function getNotifyTo(){
		return $this->redis->hGetAll($this->notifyTo);
	}
	
	/**
	 * 
	 * 获取通知
	 */
	private function getNotify() {
		return $this->redis->get($this->notify);
	}
	
	/**
	 * 
	 * 设置链表过期时间
	 * @param int $time 过期时间
	 */
	private function setExpire($time){
		return $this->redis->set($this->expire, time() + $time);
	}
	
	/**
	 * 
	 * 获取链表过期时间
	 */
	private function getExpire() {
		return $this->redis->get($this->expire);
	}
	
	/**
	 * 
	 * 错误提示
	 * @param string $msg
	 */
	public function warn($msg) {
		die($msg . "\n");
	}

}


$l = new CacheList("testList", "id");
$l->delList();
$runtime = new runtime();

$list = $list1 = array();

//100条数据
for ($i = 100; $i <= 200; $i++) {
	$list[] = array("id" => $i, "V" => "V" . $i);		
}
for ($i = 200; $i <= 1200; $i++) {
	$list1[] = array("id" => $i, "V" => "V" . $i);		
}
$runtime->start();
$l->addList($list, 100);
$runtime->stop();
echo "insert total : " . $runtime->spent(). "ms\n";
$runtime->start();
$ol = $l->getList(0, 100);
$runtime->stop();
echo "get total : " . $runtime->spent(). "ms\n";

//1000数据
$runtime->start();
$l->addList($list1, 100);
$runtime->stop();
echo "insert total : " . $runtime->spent(). "ms\n";
$runtime->start();
$ol = $l->getList(0, 1000);
$runtime->stop();
echo "get total : " . $runtime->spent(). "ms\n";