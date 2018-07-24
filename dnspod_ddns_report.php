<?php
/*
 * ======================================= 脚本说明 =======================================
 * 用途: 用于提交本地公网IP到DNSPOD, 跟你的域名进行绑定
 * 使用场景: 用于解析域名A记录的IP不固定, IP会不断的发生改变的场景
 * 附注: 此脚本仅实现了域名A记录的解析操作, 如需MX, CNAME请自行实现
 * ======================================= 使用说明 =======================================
 * linux环境: 自行添加crontab的计划任务
 * windows环境: 自行添加windows的计划任务
 * 如何触发脚本: php -f /path/to/your_filename.php
 * 附注: php命令注意环境变量的问题, 其次计划任务的频率推荐10分钟执行一次即可
 * ======================================= 注意事项 =======================================
 * 如果1小时之内, 提交了超过5次没有任何变动的记录修改请求, 该记录会被系统锁定1小时, 不允许再次修改
 * 如何理解没有任何变动的记录修改请求? 比如原记录值已经是 1.1.1.1, 新的请求还要求修改为 1.1.1.1
 * 附注: 此脚本已解决相同IP重复提交问题, 如自行修改过程序, 注意不要触发DNSPOD上面的规则流控
 * ========================================================================================
 * Date: 2018-07
 */
header("content-type:text/html; chartset=utf-8");
date_default_timezone_set('PRC');
error_reporting(E_ALL ^ E_NOTICE);
set_time_limit(600);

///////////////////////////////////////// 参数配置 /////////////////////////////////////////

//dnspod的账号
$account = 'example@mail.com';

//API Token - 填写生成的Token值
$token = '请替换成自己的token值'; // $token跟$tokenID的生成方法见:  https://support.dnspod.cn/Kb/showarticle/tsid/227/

//API Token ID - 填写生成的ID值
$tokenID = '请替换成自己的tokenid值';

//是否开启上报日志功能
$enableLog = true; //可选值为  true或false   true:开启日志功能  false:关闭日志输出

//获取公网IP的方法
$ipFetchMethod = 1; //1为通过API接口方式   2为ssh方式获取, 脚本运行环境必须为linux(请根据实际情况自行实现)

/***
 * domain_id 或 domain, 分别对应域名ID和域名, 提交其中一个即可
 * record_id 记录ID, 必选
 * sub_domain 主机记录, 如 www, 可选, 如果不传, 默认为 @
 * record_type 记录类型, 通过API记录类型获得, 大写英文, 比如：A, 必选
 * record_line 记录线路, 通过API记录线路获得, 中文, 比如：默认, 必选
 * record_line_id 线路的ID, 通过API记录线路获得, 英文字符串, 比如：'10=1'[record_line 和 record_line_id 二者传其一即可, 系统优先取 record_line_id]
 * value 记录值, 如 IP:200.200.200.200, CNAME: cname.dnspod.com., MX: mail.dnspod.com., 必选
 * mx {1-20} MX优先级, 当记录类型是 MX 时有效, 范围1-20, mx记录必选
 * ttl {1-604800} TTL, 范围1-604800, 不同等级域名最小值不同, 可选
 * status ["enable", "disable"], 记录状态, 默认为"enable", 如果传入"disable", 解析不会生效, 也不会验证负载均衡的限制, 可选
 * weight 权重信息, 0到100的整数, 可选。仅企业 VIP 域名可用, 0 表示关闭, 留空或者不传该参数, 表示不设置权重信息
 */
//定义需要操作的域名列表(支持多条, 以数组形式定义, 数组下标参考上面的请求参数描述)
$list = [
    'a.com' => [ //以域名作为数组下标的key值
        'domain'      => 'a.com', //域名
        'sub_domains' => [ //主机记录 其中的record_id用F12调试工具, 自己监控XHR请求看Prview返回的json数据
            ['record_id' => '123456789', 'sub_domain' => '@'],
            ['record_id' => '123456790', 'sub_domain' => 'www'],
            ['record_id' => '123456791', 'sub_domain' => 'home'],
        ],
        'record_type' => 'A', //记录类型  此脚本只测试了A记录, MX跟CNAME未测试.
        'record_line' => '默认', //线路类型
        'value'       => '', //记录值   如 IP:200.200.200.200必选
    ],
    // 'b.com' => [
    //     'domain'      => 'b.com',
    //     'sub_domains' => [
    //         ['record_id' => '171258881', 'sub_domain' => '@'],
    //         ['record_id' => '171258882', 'sub_domain' => 'www'],
    //     ],
    //     'record_type' => 'A',
    //     'record_line' => '默认',
    //     'value'       => '',
    // ],
];

/**************************** 以下变量值不推荐修改(除非很清楚的知道自己在做什么?) ****************************/

//定义公网IP默认为空
$ip = '';

//需要上报的域名列表
$needReportDomainList = [];

//定义接口请求的地址
$apiURL = 'https://dnsapi.cn/Record.Modify';

//postData方法中需要用到的公共参数
$commonPostData = [
    'login_token'    => $tokenID . ',' . $token,
    'format'         => 'json',
    'lang'           => 'cn',
    'error_on_empty' => 'no',
];

//每一条记录上报需要等待的更新时间间隔, 比如你有3个子域名, 则更新完3条记录至少需要30秒.
$sleep = 10;

//用于保存IP记录的文件名称
$ipLogFileName = 'lastip.txt';

//上报日志的文件名称
$logReportFileName = 'reportlog.log';

//域名上报失败的记录文件名称  !!!不要修改此文件中的内容!!!
$failReportDomainFileName = 'failReportDomain.log';

//ssh用户名
$ssh = '替换成SSH账号';

//ssh主机IP
$router = '主机的IP地址|域名|hostname'; //值可以是IP或hostname或域名, 务必保证运行脚本的机器能telnet上

//上报失败的域名数组列表
$failReportDomainArrayList = [];

//是否执行过完全更新上报域名的任务
$executeFullUpdate = false;

///////////////////////////////////////// 函数方法 /////////////////////////////////////////

/**
 * 写日志到文件
 */
function logresult($message = '')
{
    global $enableLog, $logReportFileName;
    if ($enableLog !== true || empty($message)) {
        return false;
    }

    $date    = date('[Y-m-d H:i:s] ');
    $content = $date . $message . "\n";
    $fp      = fopen($logReportFileName, 'a+');
    fwrite($fp, $content);
    fclose($fp);
}

/**
 * 通过API接口方式获取公网IP
 */
function getIPByAPI()
{
    global $ip;
    $arr    = [];
    $url    = 'http://pv.sohu.com/cityjson?ie=utf-8';
    $result = @file_get_contents($url);
    if ($result === false) {
        exit('通过API接口方式获取公网IP失败');
    }
    preg_match('/"cip".*:.*"(.*)"/U', $result, $arr);
    $ip = trim($arr[1]);
}

/**
 * 通过SSH的方式获取公网IP
 */
function getIPBySSH()
{
    global $ip, $ssh, $router;
    $cmd = 'ssh ' . $ssh . '@' . $router . ' \'/sbin/ifconfig ppp0 | grep addr | cut -d":" -f2 | cut -d " " -f1\'';
    $ip  = system($cmd);
    $ip  = $ip === false ? '' : trim($ip);
}

/**
 * 获取当前的公网IP地址
 */
function getInternetIP()
{
    global $ip, $ipFetchMethod;
    $ipFetchMethodNat = ["1" => "API接口", "2" => "SSH"];
    logresult('通过[' . $ipFetchMethodNat[$ipFetchMethod] . ']方式开始获取公网IP');
    if ($ipFetchMethod == 2) {
        getIPBySSH();
    } else {
        getIPByAPI();
    }
    if (empty($ip)) {
        logresult('公网IP获取失败');
    } else {
        logresult('公网IP获取成功 => ' . $ip);
    }
}

/**
 * 更新IP文本记录
 */
function updateIPLog()
{
    global $ipLogFileName, $ip;
    if (!$ip) {
        exit('IP不能为空');
    }
    return file_put_contents($ipLogFileName, $ip);
}

/**
 * 检测IP是否需要上报
 * 上报规则: 公网IP更新后才上报, 否则忽略上报动作
 * 返回值: boolean
 * 返回值描述: true为需要上报, false为不需要
 */
function isNeedReportIP()
{
    global $ipLogFileName, $ip;
    getInternetIP();
    if (empty($ip)) {
        return false;
    }
    $lastIP = @file_get_contents($ipLogFileName);
    if ($lastIP != $ip) {
        //IP发生变化, 更新IP文本记录
        if (updateIPLog() === false) {
            //写文件失败
            logresult("ERROR: 公网IP发生了变化, 但是写文件出错了, 跳过更新");
            return false;
        }
        return true;
    }

    if ($lastIP == $ip) {
        logresult("NOTICE: 公网IP未发生变化, 跳过更新");
        return false;
    }
    //其他情况一律返回不需要上报
    logresult("NOTICE: 存在其他未考虑到的情况, 忽略此次的更新操作");
    return false;
}

/**
 * 需要上报的域名数据处理
 * 把需要更新的域名重新组合成最终需要上报的数组数据
 */
function needReportDomain()
{
    global $list, $ip, $needReportDomainList;
    if (!is_array($list) && count($list) == 0) {
        return false;
    }
    foreach ($list as $key => $value) {
        foreach ($value['sub_domains'] as $k => $v) {
            $needReportDomainList[$key][] = [
                'domain'      => $value['domain'],
                'record_id'   => $v['record_id'],
                'sub_domain'  => $v['sub_domain'],
                'record_type' => $value['record_type'],
                'record_line' => $value['record_line'],
                'value'       => $ip,
                //'value'       => '11.111.11.122,',
            ];
        }
    }
}

/**
 * 请求API接口, 发送数据
 */
function postData($data)
{
    global $apiURL, $account, $commonPostData, $failReportDomainArrayList;
    echo '<pre/>';
    if ($apiURL == '' || !is_array($data)) {
        logresult('ERROR: postData方法中参数有误');
        exit();
    }
    $ch = @curl_init();
    if (!$ch) {
        logresult('ERROR: 服务器不支持CURL, 请确认是否安装了php-curl扩展');
        exit();
    }
    $data = array_merge($data, $commonPostData);
    // print_r($data);
    // echo '<br/>';
    // echo 'http_build_query:' . http_build_query($data);
    // echo '<hr/>';

    curl_setopt($ch, CURLOPT_URL, $apiURL);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_SSLVERSION, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_USERAGENT, 'DNSPOD DDNS - PHP CLI/1.0 (' . $account . ')');
    $result = curl_exec($ch);
    curl_close($ch);
    if (!$result) {
        $msg = 'ERROR: curl_exec执行失败, 错误信息: ' . curl_error($ch);
        logresult($msg);
        exit($msg);
    }
    // echo '<hr/>';
    // echo $result . '<br/>';
    $resultArr = json_decode($result, true);
    print_r($resultArr);
    if (is_array($resultArr) && $resultArr['status']['code'] != '1') {
        //记录上报成功, 但远程接口更新记录失败
        $failReportDomainArrayList[] = $data;
        $msg                         = 'API远程接口执行ERROR: 错误代码: ' . $resultArr['status']['code'] . '; 错误信息: ' . $resultArr['status']['message'];
        $msg2                        = "API接口返回的原始json数据为: " . json_encode($resultArr, 256);
        logresult($msg);
        logresult($msg2);
        echo $msg . "\n";
        return false;
    } else {
        logresult("当前域名更新成功. " . json_encode($resultArr, 256));
        return true;
    }
    return false;
}

/**
 * 遍历数据并调用接口
 * $data 为空为完全更新   否则为断点续传(重传失败的上报记录)
 */
function execute($data = [])
{
    global $needReportDomainList, $sleep, $failReportDomainArrayList, $failReportDomainFileName, $executeFullUpdate;
    $index = 1;
    $sleep = intval($sleep) < 3 ? 3 : $sleep; //每一条记录上报需要等待的更新时间间隔
    //完全更新
    if (is_array($data) && count($data) == 0 && is_array($needReportDomainList) && count($needReportDomainList) > 0) {
        $flag              = "全量更新";
        $executeFullUpdate = true;
        foreach ($needReportDomainList as $key => $value) {
            foreach ($needReportDomainList[$key] as $k => $v) {
                logresult("处理第{$index}条数据: " . json_encode($v, 256));
                $result = postData($v);
                sleep($sleep);
                ++$index;
            }
        }
    }

    //断点续传
    if (is_array($data) && count($data) > 0) {
        $flag = "断点续传";
        foreach ($data as $key => $value) {
            logresult("处理第{$index}条数据: " . json_encode($value, 256));
            $result = postData($value);
            sleep($sleep);
            ++$index;
        }
    }

    $fp = fopen($failReportDomainFileName, 'w+');

    //判断是否存在提交失败的记录
    if (count($failReportDomainArrayList) > 0) {
        logresult("NOTICE: 存在上报失败的域名记录, 详情见日志记录[{$failReportDomainFileName}] --IMPORTANT--");
        //把失败的记录写到文件中
        $jsonContent = json_encode($failReportDomainArrayList, 64 | 128 | 256);
        fwrite($fp, $jsonContent);
    } else {
        fwrite($fp, ''); //清空日志记录
        logresult("SUCCESS: 域名记录{$flag}已完毕 √√√");
    }
    fclose($fp);
    echo '<pre/>';
    print_r($needReportDomainList);
}

/**
 * 获取上报失败的记录并执行(断点续传)
 */
function getFailReportLogAndExecute()
{
    global $failReportDomainFileName;
    $failReportJsonContent = @file_get_contents($failReportDomainFileName);
    if (!$failReportJsonContent) {
        //无失败记录, 直接返回
        return false;
    }
    $failReportArrayContent = @json_decode($failReportJsonContent, true);
    if (is_array($failReportArrayContent) && count($failReportArrayContent) > 0) {
        logresult("NOTICE: 断点续传更新开始");
        execute($failReportArrayContent);
    }
}

//程序初始化并开始执行
function run()
{
    global $executeFullUpdate;
    //检测公网IP是否发生了变更
    if (isNeedReportIP()) {
        //IP公网发生变化
        needReportDomain(); //处理需要上报的域名
        execute();
    }
    //开始检测是否存在上报失败的记录(不管公网IP有没有发生变化,下面的程序都会执行)
    $executeFullUpdate == true || getFailReportLogAndExecute();
}

///////////////////////////////////////// 执行程序 /////////////////////////////////////////

logresult("==================== 脚本开始执行 ====================");

run();

logresult("==================== 脚本执行结束 ====================\n\n");
