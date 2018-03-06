<?php

$board = new Message();
$board->go();

class message{
	protected $db;
	protected $form_error = array();
	protected $inTransaction = false;
	public function __construct(){
		set_exception_handler(array($this,'logAndDie'));
		$this->db = new PDO('mysql:host=localhost;port=3306;dbname=thread','root','root',array(PDO::MYSQL_ATTR_INIT_COMMAND=>"set names 'utf8'"));
		$this->db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
	}
	public function go(){
		// $_REQUEST['cmd'] 告诉我们要做什么
		$cmd = isset($_REQUEST['cmd']) ? $_REQUEST['cmd'] :'show';
		switch($cmd){
			case 'read':
				$this->read();
				break;
			case 'post':
				$this->post();
				break;
			case 'save':
				if($this->validata()){
					$this->save();
					$this->show();
				}else{
					$this->post();
				}
				break;
				case 'show':
				default:
					$this->show();
				break;
		}
	}
	public function save(){
		$parent_id = isset($_REQUEST['parent_id']) ? intval($_REQUEST['parent_id']) :0;
		//确保处理留言时留言不会改变
		$this->db->beginTransaction();
		$this->inTransaction = true;

		//这个留言时回应吗？
		if($parent_id){
			//获取父留言中的线索，级别和在线索中的位置
			$st = $this->db->prepare("select thread_id,level,thread_pos 
				from message where id=?");
			$st->execute(array($parent_id));
			$parent = $st->fetch();

			//回应的级别比父留言的级别大1
			$level = $parent['level'] + 1;

			//这个线索中有相同父留言的所有留言中最大的thread_pos是什么？
			$st = $this->db->prepare("select max(thread_pos) from message where thread_id=? and parent_id=?");
			$st->execute(array($parent['parent_id'],$parent_id));
			$thread_pos = $st->fetchColumn(0);

			//这个父留言还有其他回应吗？
			if($thread_pos){
				//这个thread_pos比目前最大的thread_pos大1
				$thread_pos++;
			}else{
				//这是第一个回应，所以帮他放在父留言的后面
				$thread_pos = $parent['parent_pos'] + 1;
			}
			//将线索中位于这个留言之后的所有留言的threa_pos增1
			$st = $this->db->prepare("update message set thread_pos=thread_pos + 1 
				where thread_id=? and thread_pos>=?");
			$st->execute(array($parent['thread_id'],$thread_pos));
			//新留言的线索指定为父留言的thread_id
			$thread_id = $parent['parent_id'];
		}else{
			//这个留言不是一个回应，而是一个新线索的开始
			$thread_id = $this->db->prepare("select max(thread_id) + 1 from message")->fetchColumn(0);
			//如果还没有记录行，确保thread_id从1开始
			if(!$thread_id){
				$thread_id = 1;
			}
			$level = 0;
			$thread_pos = 0;
		}
		//将这个留言插入数据库
		$st = $this->db->prepare("insert into message (id,thread_id,parent_id,thread_pos,posted_on,level,author,subject,body) values(?,?,?,?,?,?,?,?,?)");
		$st->execute(array(null,$thread_id,$parent_id,$thread_pos,
			date('c'),$level,$_REQUEST['author'],
			$_REQUEST['subject'],$_REQUEST['body']));
		//提交所有操作
		$this->db->commit();
		$this->inTransaction = false;
	}

	protected function show(){
		echo "<h2>Message List</h2>";
		//按线索和在线索中的位置对留言安排
		$st = $this->db->query("select id,author,subject,length(body) 
			as body_length,posted_on,level from message order by thread_id,thread_pos");
		while($row = $st->fetch()){
			echo str_repeat('&nbsp', 4 * $row['level']);
			$when = date('Y-m-d H:i',strtotime($row['posted_on']));
			echo "<a href='" . htmlentities($_SERVER['PHP_SELF']) . 
			"?cmd=read&amp;id={$row['id']}'>" . 
			htmlentities($row['subject']) . '</a> by ' . 
			htmlentities($row['author']) . ' @ ' .
			htmlentities($when) .
			"({$row['body_length']} byte) <br/>";
		}

		//提供一种方法来提交非回应留言
		echo "<hr/><a href='" .
		htmlentities($_SERVER['PHP_SELF']) .
		"?cmd=post'>Start a New Thread</a>";
	}

	public function read(){
		//确保传入的ID是一个整数，而且确实表示一个留言
		if(!isset($_REQUEST['id'])){
			throw new Exception('no message id supplied');
		}
		$id = intval($_REQUEST['id']);
		$st = $this->db->prepare("select author,subject,body,posted_on from message where id=?");
		$st->execute(array($id));
		$msg = $st->fetch();
		if(!$msg){
			throw new Exception("bad message id");
		}

		//不显示面向用户的HTML，而是将换行显示为HTML换行符
		$body = nl2br(htmlentities($msg['body']));

		//显示留言，并提供链接来做出回应以及返回留言列表
		$self = $_SERVER['PHP_SELF'];
		$subject = htmlentities($msg['subject']);
		$author = htmlentities($msg['author']);
		print "
		<h2>{$subject}</h2>
		<h3>{$author}</h3>
		<p>{$body}</p>
		<hr />
		<a href='$self?cmd=post&parnet_id=$id'>Relay</a>
		<br />
		<a href='$self?cmd=list'>List Message</a>";
	}

	//显示表单来提交留言
	public function post(){
		$safe = array();
		foreach(array('author','subject','body') as $field){
			//转义默认字段值中的字符
			if(isset($_POST['$field'])){
				$safe[$field] = htmlentities($_POST[$field]);
			}else{
				$safe[$field] = '';
			}
			//将错误消息用红色显示
			if(isset($this->form_error[$field])){
				$this->form_error[$field] = "<span style='color:red'>" .
				$this->form_error[$field] ."</span></br>";
			}else{
				$this->form_error[$field] = '';
			}
		}
		//这个留言时一个回应吗
		if(isset($_REQUEST['parent_id']) && 
			$parnet_id = intval($_REQUEST['parnet_id'])){

			//提交表单发送parent_id
			$parent_field = sprintf('<input type="hidden" name="parent_id" value="%d" />',$parnet_id);

		//如果为传入主题，则使用父留言的主题
		if(!strlen($safe['subject'])){
			$st = $this->db->prepare("select subject from message where id=?");
			$st->execute(array($parnet_id));
			$parent_subject = $st->fetchColumn(0);

			//如果存在父主题而没有‘re’，则为父主题加前缀‘re’
			$safe['subject'] = htmlentities($parent_subject);
			if($parent_subject && (! preg_match('/^re:/i',$parent_subject))){
				$safe['subject'] = "Re:{$safe['subject']}";
			}
		}
	}else{
		$parent_field = '';
	}

	//显示提交表单，并提供错误和默认值
	$self = htmlentities($_SERVER['PHP_SELF']);
	echo "<form method = 'post' action = '{$self}'>
	<table>
	<tr><td>Your name:</td>
	<td><input type = 'text' name = 'author' value = '{$safe['author']}' /></td>
	</tr>
	<tr><td>Subject:</td>
	<td>{$this->form_error['subject']}<input type = 'text' name = 'subject' value = '{$safe['subject']}' /></td>
	</tr>
	<tr><td>Message:</td>
	<td>{$this->form_error['body']}<textarea rows='4' cols='30' name = 'body'>{$safe['body']}</textarea></td>
	</tr>
	<tr><td colspan='2'><input type='submit' value = 'Post Message' /></td>
	</tr>
	</table>
	$parent_field
	<input type = 'hidden' name = 'cmd' value = 'save' />
	</form>
		";
	}

	// validate()确保在各个域中输入内容
	public function validata(){
		$this->form_error = array();
		if(! isset($_POST['author']) && strlen(trim($_POST['author']))){
			$this->form_error['author'] = "Pleasse enter your name.";
		}
		if(! isset($_POST['subject']) && strlen(trim($_POST['subject']))){
			$this->form_error['subject'] = "Pleasse enter your subject.";
		}
		if(! isset($_POST['body']) && strlen(trim($_POST['body']))){
			$this->form_error['body'] = "Pleasse enter your message body.";
		}
		return (count($this->form_error) ==0);
	}

	public function logAndDie(Exception $e){
		echo "ERROR" . htmlentities($e->getMessage());
		if($this->db && $this->db->inTransaction()){
			$this->db->rollback();
		}
		exit();
	}
}

?>