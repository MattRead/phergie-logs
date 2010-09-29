<?php
date_default_timezone_set(date_default_timezone_get());
error_reporting(E_ALL | E_STRICT );
ini_set('display_errors', true);
class IRCLogs
{
    const JOIN = 1;
    const PART = 2;
    const QUIT = 3;
    const PRIVMSG = 4;
    const ACTION = 5;
    const NICK = 6;
    const KICK = 7;
    const MODE = 8;
    const TOPIC = 9;
    const QUERY = 10;

	const DIR = '/home/matt/phergie/Plugin/Logging/';

	private $db = null;
	private $channel = null;

	public function __construct( $channel )
	{
		$this->db = new PDO('sqlite:' . IRCLogs::DIR . 'logging.db');
		$this->channel = $channel;
	}

	public function doHTMLGrep( $term )
	{
		$term = trim(str_replace('*', '%', $term), '%');
		$qClause = "WHERE message like ? AND chan = ?";
		$params = array("%$term%", $this->channel);

		$q = $this->db->prepare(
			'SELECT tstamp, type, chan, nick, message FROM logs ' . $qClause
		);

		$q->execute($params);
		$logs = $q->fetchAll();
		$q = null;
		$this->logsHTML( $logs, $term, '[y-m-d]' );
	}

	public function doHTMLListDates()
	{
		$qClause = "WHERE chan = ?";
		$params = array($this->channel);

		$q = $this->db->prepare(
			'SELECT DISTINCT date(tstamp) as date FROM logs ' . $qClause .' ORDER BY date DESC'
		);
		$q->execute($params);
		$dates = $q->fetchAll();

		printf("<h1>%s Logs</h1>", $this->channel);
		foreach( $dates as $date ) {
			printf("<li><a href=\"/irc/%s/%s\">%s</a></li>", ltrim($this->channel, '#'), $date['date'], $date['date']);
		}
		echo "</ul>";
	}

	public function doHTMLLogs($date)
	{
		list($year, $month, $day) = split('-', $date);
		$day = strftime('%j', mktime(0, 0, 0, $month, $day, $year));
		$topic = $this->getTopic($day, $year);
                $topic = isset($topic[0]['message']) ? "<h2>{$topic[0]['message']}</h2>" : '';
		$this->logsHTML( $this->getDailyLogs($day, $year), $date, $topic );
	}

	public function logsHTML( array $logs, $label, $topic, $date_format = '[H:i:s]' )
	{
		$out = array();
		foreach ( $logs as $log ) {
			$time = strtotime($log['tstamp']);
			switch ( $log['type'] ) {
				case self::QUERY:
				case self::ACTION:
					$out[] = array(
						'time' => $time,
						'line' => sprintf(
							"<span class=\"msg\">* %s %s</span>\n",
							$log['nick'],
							htmlspecialchars($log['message'])
						),
						'log' => $log
					);
					break;
				case self::PRIVMSG:
					$out[] = array(
						'time' => $time,
						'line' => sprintf(
							"<span class=\"nick\">&lt;%s&gt;</span> %s\n",
							$log['nick'],
							htmlspecialchars($log['message'])
						),
						'log' => $log
					);
					break;
				case self::QUIT:
				case self::PART:
					$out[] = array(
						'time' => $time,
						'line' => sprintf(
							"<span class=\"serv quit\">*** %s has left %s (%s)</span>\n",
							$log['nick'],
							$log['chan'],
							htmlspecialchars($log['message'])
						),
						'log' => $log
					);
					break;
				case self::JOIN:
					$out[] = array(
						'time' => $time,
						'line' => sprintf(
							"<span class=\"serv join\">*** %s has entered %s</span>\n",
							$log['nick'],
							$log['chan']
						),
						'log' => $log
					);
					break;
				case self::NICK:
					$out[] = array(
						'time' => $time,
						'line' => sprintf(
							"<span class=\"serv\">*** %s is now known as %s</span>\n",
							$log['nick'],
							$log['message']
						),
						'log' => $log
					);
					break;
				case self::TOPIC:
					$out[] = array(
						'time' => $time,
						'line' => sprintf(
							"<span class=\"serv\"> *** %s has change topic to: <b>%s</b></span>\n",
							$log['nick'],
							htmlspecialchars($log['message'])
						),
						'log' => $log
					);
					break;
				case self::MODE:
					$out[] = array(
						'time' => $time,
						'line' => sprintf(
							"<span class=\"serv\"> *** %s set mode: %s</span>\n",
							$log['nick'],
							htmlspecialchars($log['message'])
						),
						'log' => $log
					);
					break;
				case self::KICK:
					$out[] = array(
						'time' => $time,
						'line' => sprintf(
							"<span class=\"serv\">*** %s has been kicked from %s by %s</span>\n",
							htmlspecialchars($log['message']),
							$log['chan'],
							$log['nick']
						),
						'log' => $log
					);
					break;
			}
		}
		printf("<h1>%s: %s</h1>%s<p>", $this->channel, $label, $topic);
		$lastnick = null;
		foreach ( $out as $line ) {

            $line['line'] = preg_replace( '@https?://([^\s<]+)@i', '<a href="$0">$0</a>', $line['line'] );
			$line['line'] = preg_replace( '/(\s)(@([^\s]+))/i', '$1<a href="http://identi.ca/$3">$2</a>', $line['line'] );
            $line['line'] = preg_replace( '/(\s)(#(\d+))/i', '$1<a href="https://trac.habariproject.org/habari/ticket/$3">$2</a>', $line['line'] );
            $line['line'] = preg_replace( '/(\s)(r(\d+))/i', '$1<a href="https://trac.habariproject.org/habari/changeset/$3">$2</a>', $line['line'] );
			
			printf(
				'<a id="%s" href="/irc/%s/%s" class="time">%s</a><span class="line"> %s</span>',
				date('\TH-i-s', $line['time']),
				ltrim($this->channel, '#'),
				date('Y-m-d#\TH-i-s', $line['time']),
				date($date_format, $line['time']),
				nl2br($line['line'])
			);
		}

		echo "</p>";
	}

	public function getDailyLogs($day, $year)
	{
		$qClause = "WHERE strftime('%j', tstamp) = ? AND strftime('%Y', tstamp)  = ? AND chan = ?";
		$params = array($day,$year, $this->channel);

		$q = $this->db->prepare(
			'SELECT tstamp, type, chan, nick, message FROM logs ' . $qClause
		);
		$q->execute($params);
		$return = $q->fetchAll();
		$q = null;
		return $return;
	}

        public function getTopic($day, $year)
        {
                $qClause = "WHERE strftime('%j', tstamp) <= ? AND strftime('%Y', tstamp) <= ? AND type = 9 AND chan = ? ORDER BY tstamp DESC LIMIT 1";
                $params = array($day, $year, $this->channel);

                $q = $this->db->prepare(
                        'SELECT message FROM logs ' . $qClause
                );
                $q->execute($params);
                $return = $q->fetchAll();
                $q = null;
                return $return;
        }
}

if ( !isset($_GET['path']) ) $_GET['path'] = '';
list($channel,$date) = array_pad(explode('/', trim($_GET['path'], '/'), 2), 2, null);

$channel = '#'.strip_tags($channel);
if (strpos($date, 'grep') === 0) {
	list($date, $grep) = explode('/', $date, 2);
	$date = null;
}
else {
	$grep = null;
}

$logs = new IRCLogs($channel);
?>
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<title>IRC Logs</title>
<style type="text/css">
body { font-family: sans-serif; padding:1em 4em; line-height:140%; color:#010101; background:#f3f3f3; }
.time { font-size:.8em; display:block; float:left; clear:left; width:80px; }
.line { float:left; width:600px; }
.nick { font-size:.9em; font-weight:bold; }
.serv { font-size:.9em; color: #5C3566; }
.join { color: #4E9A06; }
.quit { color: #8F5902; }
li { font-size:.8em; list-style-type: square; margin:0; padding:0; }
h1 { font-family: Georgia, serif; font-weight:normal; font-size:60px; }
h2 { font-family: Georgia, serif; font-weight:normal; font-size:20px; }
</style>
</head>
<body>
<?php
if ($channel == '#gulbac') {
	echo "<p>No logs allowed.</p>";
}
elseif ( $channel == '#' ) {
	echo '<h1>Available Channels</h1><ul>
	<li><a href="habari">#habari</a></li>
	<li><a href="linuxoutlaws">#linuxoutlaws</a></li>
	<li><a href="phergie">#phergie</a></li>
	</ul>';
}
elseif ( $grep ) {
	$logs->doHTMLGrep($grep);
}
elseif ( $date ) {
	$logs->doHTMLLogs($date);
}
else {
	$logs->doHTMLListDates();
}

?>
</body></html>
