# dnspod_ddns
动态IP跟域名绑定的解析服务


**脚本说明**<br/>
用途: 用于提交本地公网IP到DNSPOD, 跟你的域名进行绑定<br/>
使用场景: 用于解析域名A记录的IP不固定, IP会不断发生改变的场景<br/>
附注: 此脚本仅实现了域名A记录的解析操作, 如需MX, CNAME请自行实现<br/>


**使用说明**<br/>
linux环境: 自行添加crontab的计划任务<br/>
windows环境: 自行添加windows的计划任务<br/>
如何触发脚本: php -f /path/to/your_filename.php<br/>
附注: php命令注意环境变量的问题, 其次计划任务的频率推荐10分钟执行一次即可<br/>


**注意事项**<br/>
如果1小时之内, 提交了超过5次没有任何变动的记录修改请求, 该记录会被系统锁定1小时, 不允许再次修改<br/>
如何理解没有任何变动的记录修改请求? 比如原记录值已经是 1.1.1.1, 新的请求还要求修改为 1.1.1.1<br/>
附注: 此脚本已解决相同IP重复提交问题, 如自行修改过程序, 注意不要触发DNSPOD上面的规则流控<br/>


**Linux系统中计划任务的添加方法(以Debian Ubuntu为例)** <br/>
方法一:<br/>
<pre><code>
# echo "*/10 * * * * root	 cd /shell/path && /usr/bin/php -f dnspod_ddns_report.php > /dev/null" >> /etc/crontab
</code></pre>


方法二:<br/>
<pre><code>
# crontab -e
添加下面一行内容
*/10 * * * * cd /shell/path && /usr/bin/php -f dnspod_ddns_report.php > /dev/null
</code></pre>
