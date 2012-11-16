<?php

/*
    Author: Jim Westergren & Jeedo Aquino
    File: index-with-redis.php
    Updated: 2012-10-25

    This is a redis caching system for wordpress.
    see more here: www.jimwestergren.com/wordpress-with-redis-as-a-frontend-cache/

    Originally written by Jim Westergren but improved by Jeedo Aquino.

    some caching mechanics are different from jim's script which is summarized below:

    - cached pages do not expire not unless explicitly deleted or reset
    - appending a ?c=y to a url deletes the entire cache of the domain, only works when you are logged in
    - appending a ?r=y to a url deletes the cache of that url
    - submitting a comment deletes the cache of that page
    - refreshing (f5) a page deletes the cache of that page
    - includes a debug mode, stats are displayed at the bottom most part after </html>

    for setup and configuration see more here:

    www.jeedo.net/lightning-fast-wordpress-with-nginx-redis/

    use this script at your own risk. i currently use this albeit a slightly modified version
    to display a redis badge whenever a cache is displayed.

*/

// change vars here
$cf = 1;			// set to 1 if you are using cloudflare
$debug = 0;			// set to 1 if you wish to see execution time and cache actions
$display_powered_by_redis = 1;  // set to 1 if you want to display a powered by redis message with execution time, see below

$start = microtime();   // start timing page exec

// if cloudflare is enabled
if ($cf) {
    if (isset($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $_SERVER['REMOTE_ADDR'] = $_SERVER['HTTP_CF_CONNECTING_IP'];
    }
}

// from wp
define('WP_USE_THEMES', true);

// init predis
include("./predis.php");
$redis = new Predis\Client('array(
    'host'   => $_SERVER['CACHE1_HOST'],
    'port'   => $_SERVER['CACHE1_PORT'],
));

// init vars
$domain = $_SERVER['HTTP_HOST'];
$url = "http://".$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];
$url = str_replace('?r=y', '', $url);
$url = str_replace('?c=y', '', $url);
$dkey = md5($domain);
$ukey = md5($url);

// check if page isn't a comment submission
(($_SERVER['HTTP_CACHE_CONTROL'] == 'max-age=0') ? $submit = 1 : $submit = 0);

// check if logged in to wp
$cookie = var_export($_COOKIE, true);
$loggedin = preg_match("/wordpress_logged_in/", $cookie);

// check if a cache of the page exists
if ($redis->hexists($dkey, $ukey) && !$loggedin && !$submit && !strpos($url, '/feed/')) {

    echo $redis->hget($dkey, $ukey);
    $cached = 1;
    $msg = 'this is a cache';

// if a comment was submitted or clear page cache request was made delete cache of page
} else if ($submit || substr($_SERVER['REQUEST_URI'], -4) == '?r=y') {

    require('./wordpress/wp-blog-header.php');
    $redis->hdel($dkey, $ukey);
    $msg = 'cache of page deleted';

// delete entire cache, works only if logged in
} else if ($loggedin && substr($_SERVER['REQUEST_URI'], -4) == '?c=y') {

    require('./wordpress/wp-blog-header.php');
    if ($redis->exists($dkey)) {
        $redis->del($dkey);
        $msg = 'domain cache flushed';
    } else {
        $msg = 'no cache to flush';
    }

// if logged in don't cache anything
} else if ($loggedin) {

    require('./wordpress/wp-blog-header.php');
    $msg = 'not cached';

// cache the page
} else {

    // turn on output buffering
    ob_start();

    require('./wordpress/wp-blog-header.php');

    // get contents of output buffer
    $html = ob_get_contents();

    // clean output buffer
    ob_end_clean();
    echo $html;

    // store html contents to redis cache
    $redis->hset($dkey, $ukey, $html);
    $msg = 'cache is set';
}

$end = microtime(); // get end execution time

// show messages if debug is enabled
if ($debug) {
    echo $msg.': ';
    echo t_exec($start, $end);
}

if ($cached && $display_powered_by_redis) {
	// You should move this CSS to your CSS file and change the: float:right;margin:20px 0;
	echo "<style>#redis_powered{float:right;margin:20px 0;background:url(http://images.staticjw.com/jim/3959/redis.png) 10px no-repeat #fff;border:1px solid #D7D8DF;padding:10px;width:190px;}
	#redis_powered div{width:190px;text-align:right;font:10px/11px arial,sans-serif;color:#000;}</style>";
	echo "<a href=\"http://www.jimwestergren.com/wordpress-with-redis-as-a-frontend-cache/\" style=\"text-decoration:none;\"><div id=\"redis_powered\"><div>Page generated in<br/> ".t_exec($start, $end)." sec</div></div></a>";
}

// time diff
function t_exec($start, $end) {
    $t = (getmicrotime($end) - getmicrotime($start));
    return round($t,5);
}

// get time
function getmicrotime($t) {
    list($usec, $sec) = explode(" ",$t);
    return ((float)$usec + (float)$sec);
}

?>
