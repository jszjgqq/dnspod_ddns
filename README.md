# dnspod_ddns
动态IP跟域名绑定的解析服务


脚本说明
用途: 用于提交本地公网IP到DNSPOD, 跟你的域名进行绑定
使用场景: 用于解析域名A记录的IP不固定, IP会不断发生改变的场景
附注: 此脚本仅实现了域名A记录的解析操作, 如需MX, CNAME请自行实现


使用说明
linux环境: 自行添加crontab的计划任务
windows环境: 自行添加windows的计划任务
如何触发脚本: php -f /path/to/your_filename.php
附注: php命令注意环境变量的问题, 其次计划任务的频率推荐10分钟执行一次即可


注意事项
如果1小时之内, 提交了超过5次没有任何变动的记录修改请求, 该记录会被系统锁定1小时, 不允许再次修改
如何理解没有任何变动的记录修改请求? 比如原记录值已经是 1.1.1.1, 新的请求还要求修改为 1.1.1.1
附注: 此脚本已解决相同IP重复提交问题, 如自行修改过程序, 注意不要触发DNSPOD上面的规则流控


Linux系统中计划任务的添加方法(以Debian/Ubuntu为例)
方法一:
# echo "*/10 * * * * root	 cd /shell/path && /usr/bin/php -f dnspod_ddns_report.php > /dev/null"

方法二:
# crontab -e
添加下面一行内容
*/10 * * * * cd /shell/path && /usr/bin/php -f dnspod_ddns_report.php > /dev/null
