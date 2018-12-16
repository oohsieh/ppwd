Skip to content
 
Search or jump to…

Pull requests
Issues
Marketplace
Explore
 @oohsieh Sign out
0
0 0 oohsieh/ppwd
 Code  Issues 0  Pull requests 0  Projects 0  Wiki  Insights  Settings
ppwd/統一發票.txt
5f3428b  2 hours ago
 技術一\謝典樺 initialize the ppwd file
     
527 lines (435 sloc)  14.7 KB
<?
use lib\dbz\ssdbDAO;
use Pchomepay\Api\WithdrawApi;

//================== PP API ===================
/*
$api = new WithdrawApi;

//4.4.18 平台指定提領
$result = $api->withdrawAssign([
    'pla_withdraw_id' => 'XXXXX112312312',
    'pla_mem_id' => '123123123',
    'wallet' => 'P,G,S,C',
    'amount' => 100
]);
print_r($result);

//4.4.19 提領查詢
$result = $api->withdrawQuery([
    'pla_withdraw_id' => 'XXXXX112312312',
]);
print_r($result);

//4.4.20 會員帳本餘額查詢
$result = $api->walletBalance([
    'pla_mem_id' => '123123123',
    'wallet' => 'P,G,S,C',
]);
print_r($result);
*/
//=============================================

# 執行紀錄log檔
# 進行中資料txt 只有一筆紀錄
# error log
# 

# ======= ERROR TYPE ==========
    queue資料錯誤 => [ERR] error_log => end

  api--HTTP => error_log & push queue & sleep
  api--auth_error => error_log & send sms & exit

  api查詢餘額--計算結果餘額不足 => [OK] => end

  api提領--提領編號重複 => api查詢提領狀態
  api提領--request欄位驗證有誤 => [FL] error_log => end
  api提領--會員不存在          => [FL] error_log => end
  api提領--可提領金額必須大於0  => [FL] error_log => end
  api提領--可提領金額不足      => [FL] error_log => end

  api查詢提領狀態--處理中 => wait_log => end
  api查詢提領狀態--成功  => [OK] => end
  api查詢提領狀態--失敗  => [FL] => end
  api查詢提領狀態--request欄位驗證有誤 => error_log => end
  api查詢提領狀態--提領資料不存在,表示未提領 => push queue


# ssdb連線中斷

# oracle中斷

$wallet_sort = 'ACS';
$wallet_rate = array('A' => 0.6, 'S' => 0, 'C' => 0.4);


function do_Qppdw_process(){
  global $my_env;
  $debug = 0;
  $target_file = "/export/home/webuser/c2c/bin/queue/".preg_replace("/\.php/", "", basename(__FILE__)).".txt";
  $fh = fopen($target_file, 'a+');
  pcntl_signal(SIGINT, "sig_handler");
  pcntl_sigprocmask(SIG_BLOCK, array(SIGUSR1));

  $ppApi = new WithdrawApi;

## 讀檔有無未完成
  if ($str = fgets($fh, 1024)) {
    $wdata = '';
    $arr = json_decode($str, true);
    $sql = "select pp_wd_status, spay_amt, spay_date, cpay_amt, cpay_date from c_pp_wd where pp_wd_no = '".$arr['no']."' and pp_wd_status not in ('OK', 'FL', 'ERR')";
    ora_connect();
    if ($ppay = ora_sql_query($sql)) {
      if ($ppay[0] == 'INI') {
        $wdata = $arr;
      }
      elseif ($ppay[1] == 0 && $ppay[3] == 0) {
        put_errlog('OK', '', $arr);
      }
      else {
        $cnt = 0;
        if ($ppay[1] > 0 && !$ppay[2]) {
          $cnt++;
          $undone['S'] = $arr[no].'S';
        }
        if ($ppay[3] > 0 && !$ppay[4]) {
          $cnt++;
          $undone['C'] = $arr[no].'C';
        }
      }
    }

    if ($cnt) { 
      foreach($undone as $wallet => $id) {
        $rqry = api_Query($ppApi, $id);
        if ($rqry == 'none') {
          $wdata = $arr;
        }
        elseif ($rqry['result'] == '1') {
          put_waitlog($id);
        }
        elseif ($rqry['result'] == '2') {
          $sql_cmd = "pp_wd_type = 'OK', ";
          if ($wallet == 'S') $sql_cmd.= "spay_amt = '".$rqry['amount']."', spay_date = '".date("Y/m/d H:i:s", $rqry['create_time'])."', ";
          if ($wallet == 'C') $sql_cmd.= "cpay_amt = '".$rqry['amount']."', cpay_date = '".date("Y/m/d H:i:s", $rqry['create_time'])."', ";
          $sql = "update ".$_e["_or_u"]."c_pp_wd set $sql_cmd upd_date = sysdate where pp_wd_no = '".$arr[no]."' ";
          $ora = ora_connect();
          ora_sql_exec($sql, $ora);
        }
        elseif ($rqry['result'] == '3') {
          if (--$num) $sql_cmd = "pp_wd_type = 'RUN', "; //二寶未完成
          else $sql_cmd = "pp_wd_type = 'FL', ";
          if ($wallet == 'S') $sql_cmd.= "spay_amt = '0', ";
          if ($wallet == 'C') $sql_cmd.= "cpay_amt = '0', ";
          $sql = "update ".$_e["_or_u"]."c_pp_wd set $sql_cmd upd_date = sysdate where pp_wd_no = '".$arr[no]."' and pp_wd_type != 'OK' ";
          $ora = ora_connect();
          ora_sql_exec($sql, $ora);
        }
        else {

        }

      }
    }
  }
  
## 先查詢提領 再決定是否執行提領


  while(1) {
    while($item = ssdb_pop()) {
      $wdata = json_decode($item, true);
      if (!preg_match("/^[0-9]+$/", $wdata[no])) {
        put_errlog('ERR', 'queue no error', $wdata);
        continue;
      }
      if (!preg_match("/^[0-9]+$/", $wdata[mid])) {
        put_errlog('ERR', 'queue mid error', $wdata);
        continue;
      }
      if (!preg_match("/^[0-9]+$/", $wdata[amt]) || $wdata[amt] <= 0) {
        put_errlog('ERR', 'queue amt error', $wdata);
        continue;
      }
      if (!preg_match("/^[0-9]+$/", $wdata[dps]) || $wdata[dps] < $wdata[amt]) {
        put_errlog('ERR', 'queue dps error', $wdata);
        continue;
      }

      ftruncate($fh, 0);
      fwrite($fh, $item);
      fflush($fh);

      $wd_amt = api_Balance($ppApi, $wdata);
      if ($wd_amt == 'exit') exit;
      if ($wd_amt == 'continue') continue;

      $rwd = api_Withdraw($wd_amt);
      if ($rwd == 'exit') exit;
      if ($rwd == 'continue') continue;
    }

    $i++;

    if (!$item) {
      pcntl_sigprocmask(SIG_UNBLOCK, array(SIGUSR1));

      if($is_over_updates){
        $is_over_updates = 0;
      }else{
        //echo $i. "次 沒資料 \n";
      }

      //echo "sleep 3秒 \n";
      sleep(3);

      if (1 == $my_env['SIGUSR1']) unlink_file($target_file);
      pcntl_sigprocmask(SIG_BLOCK, array(SIGUSR1));
    }

  }

}


//餘額
function api_Balance($ppApi, $wdata) {
  global $_e;

  $dps[A] = $wdata[dps];
  $rtry_max = 3;

  do {
    $res = $ppApi->walletBalance([
        'pla_mem_id'=>$wdata[mid],
        'wallet'=>'S,C'
    ]);

    if ($r = parse_msg($res, 'Balance')) {
      if ($r[way] === 0) { ##exit
        put_errlog('', $r[log], $wdata);
        sms("0933135121", "pp token error, server exit"); //send_sms("pp token error, server exit");
        return 'exit';
      }
      elseif ($r[way] < 0) { ##continue
        put_errlog('FL', $r[log], $wdata);
        return 'continue';
      }
      else { ##retry
        put_errlog('', $r[log], $wdata);
        $rtry++;
        usleep(500);
        continue;
      }
    }
    else break;

  } while($rtry < $rtry_max);

  if ($r) { ##重試3次仍然失敗 => 寫log => 重新push => sleep => continue
    put_errlog('', $r[log], $wdata);
    ssdb_push(json_encode($wdata));
    usleep(1000);
    return 'continue';
  }

  $dps[S] = ($res->balance_items->S->available_amount)? $res->balance_items->S->available_amount : 0;
  $dps[C] = ($res->balance_items->C->available_amount)? $res->balance_items->C->available_amount : 0;

  $wd_amt = make_wd_amt($wdata[amt], $dps); //計算提領金額
  $sql = "update ".$_e["_or_u"]."c_pp_wd set pp_wd_status = 'ING', spay_amt = '".$wd_amt[S]."', cpay_amt = '".$wd_amt[C]."', upd_date = sysdate where pp_wd_no = '".$wdata[no]."' ";
  ora_connect();
  ora_sql_exec($sql);

  return $wd_amt;
}


//提領
function api_Withdraw($ppApi, $wdata, $wd_amt) {
  global $_e;

  $num = 0;
  if ($wd_amt[S] > 0) $num++;
  if ($wd_amt[C] > 0) $num++;

  if (!$num) {
    put_errlog('OK', '', $wdata); ##免提領
    return 'continue';
  }

  $rtry_max = 3;

  foreach ($wd_amt as $wallet => $amount) {

    if ($wallet=='A' || $amount<=0) continue;
    $rtry = 0;

    do {
      $pla_withdraw_id = $wdata[no].$wallet;
      $res = $ppApi->withdrawAssign([
          'pla_withdraw_id' => $pla_withdraw_id,
          'pla_mem_id' => $wdata[mid],
          'wallet' => $wallet,
          'amount' => $amount
      ]);

      if ($r = parse_msg($res, 'Withdraw')) { //error
          if ($r[way] === 0) { ##exit
              put_errlog('', $r[log], $wdata);
              sms("0933135121", "pp token error, server exit"); //send_sms("pp token error, server exit"); ##基本上不會執行到一半才出現token錯誤
              return 'exit';
          }
          elseif ($r[way] < 0) { ##continue
              put_errlog('FL', $r[log], $wdata);
              return 'continue';
          }
          else { ## way=1 or way=11 連線問題或編號重複 => 查提領狀態
              if ($r[way] == 1) usleep(1000);
              $rqry = api_Query($ppApi, $pla_withdraw_id); //查詢提領

              if ($rqry == 'none') { //無提領資料
                  $rtry++;
                  usleep(500);
                  continue;
              }

              elseif ($rqry['status'] == '1') { //處理中 => 進待查詢
                  put_waitlog($pla_withdraw_id);
                  break;
              }

              elseif ($rqry['status'] == '2') { //成功
                  --$num;
                  $sql_cmd = "pp_wd_type = 'OK', ";
                  if ($wallet == 'S') $sql_cmd.= "spay_amt = '".$rqry['amount']."', spay_date = '".date("Y/m/d H:i:s", $rqry['create_time'])."', ";
                  if ($wallet == 'C') $sql_cmd.= "cpay_amt = '".$rqry['amount']."', cpay_date = '".date("Y/m/d H:i:s", $rqry['create_time'])."', ";
                  $sql = "update ".$_e["_or_u"]."c_pp_wd set $sql_cmd upd_date = sysdate where pp_wd_no = '".$wdata[no]."' ";
                  $ora = ora_connect();
                  ora_sql_exec($sql, $ora);
                  break;
              }

              elseif ($rqry['status'] == '3') { //失敗
                  if (--$num) $sql_cmd = "pp_wd_type = 'RUN', "; //二寶未完成
                  else $sql_cmd = "pp_wd_type = 'FL', ";
                  if ($wallet == 'S') $sql_cmd.= "spay_amt = '0', ";
                  if ($wallet == 'C') $sql_cmd.= "cpay_amt = '0', ";
                  $sql = "update ".$_e["_or_u"]."c_pp_wd set $sql_cmd upd_date = sysdate where pp_wd_no = '".$wdata[no]."' and pp_wd_type != 'OK' ";
                  $ora = ora_connect();
                  ora_sql_exec($sql, $ora);
                  break;
              }

              else {  //任何錯誤 => 進待查詢
                  put_waitlog($pla_withdraw_id);
                  break;
              }
          }
      }
      elseif ($res->result == 'OK') { //access
          --$num;
          $sql_cmd = "pp_wd_type = 'OK', ";
          if ($wallet == 'S') $sql_cmd.= "spay_date = '".date("Y/m/d H:i:s", $res->create_time)."', ";
          if ($wallet == 'C') $sql_cmd.= "cpay_date = '".date("Y/m/d H:i:s", $res->create_time)."', ";
          $sql = "update ".$_e["_or_u"]."c_pp_wd set $sql_cmd upd_date = sysdate where pp_wd_no = '".$wdata[no]."' ";
          $ora = ora_connect();
          ora_sql_exec($sql, $ora);
          break;
      }
      else { //other error
        put_waitlog($pla_withdraw_id);
        break;
      }

    } while($rtry < $rtry_max);

  }

  if ($num) {
#未完成
  }

}



function api_Query($ppApi, $id) {
  $res = $ppApi->withdrawQuery([
      'pla_withdraw_id' => $id,
  ]);
  if ($r = parse_msg($res, 'Query')) {
    put_errlog('', $r[log], $wdata);
    if ($r[way] === 0) return 'exit'; #嚴重錯誤
    elseif ($r[way] === 12) return 'none'; #提領資料不存在
    else return false; #其他一般錯誤
  }
  else return json_decode($res, true);
}


/***
{
    "curl_url": "https:\/\/dev-c2capi.pchomepay.com.tw\/v1\/member\/wallet\/balance?pla_mem_id=1875982&wallet=S%2CC&__branch__=1474-new-pool-c2capi",
    "curl_code": 502,
    "curl_message": "HTTP\/1.1 502 Bad Gateway",
    "curl_request": {
        "pla_mem_id": "1875982",
        "wallet": "S,C"
    },
    "curl_response": "<html>\r\n<head><title>502 Bad Gateway<\/title><\/head>\r\n<body bgcolor=\"white\">\r\n<center><h1>502 Bad Gateway<\/h1><\/center>\r\n<hr><center>nginx\/1.10.3 (Ubuntu)<\/center>\r\n<\/body>\r\n<\/html>\r\n"
}

Array
(
    [code] => 900
    [message] => {
    "curl_url": "https:\/\/dev-c2capi.pchomepay.com.tw\/v1\/member\/wallet\/balance?pla_mem_id=123123123&wallet=S%2CC&__branch__=1474-new-pool-c2capi",
    "curl_code": 400,
    "curl_message": "HTTP\/1.1 400 Bad Request",
    "curl_request": {
        "pla_mem_id": "123123123",
        "wallet": "S,C"
    },
    "curl_response": "{\"error_type\":\"member_error\",\"code\":113002,\"message\":\"platform member is not a PChomePay member\"}"
}
)
***/
function parse_msg($msg, $step) { //way = -1:處理下一筆 0:結束程式 1:等待重試
  if (!isset($msg['code'])) return;

  $way = &$r['way'];
  $log = &$r['log'];
  $log = $step.'@[code:'.$msg['code'].'] ';

  if ($msg['code'] == 101) {
    $log.= '=> '.$msg['message'];
  }
  elseif ($msg['code'] == 900) {
      if (preg_match("/\{.+\}/", trim($msg['message'])) && $message = json_decode($msg['message'], true)) {
          if ($message['curl_code'] <= 499) $way = -1; //一般錯誤 => 處理下一筆
          elseif ($message['curl_code'] <= 599) $way = 1; //pp server錯誤 => 等待重試
          if (preg_match("/\{.+\}/", trim($message['curl_response'])) && $curl_response = json_decode($message['curl_response'], true)) {
              if ($curl_response['code'] > 110000 && $curl_response['code'] <= 110004) $way = 0; //嚴重錯誤 => 結束程式(帳密錯誤,伺服器IP錯誤,token錯誤)
              if ($curl_response['code'] == 116007) $way = 11; //提領編號重複
              if ($curl_response['code'] == 116008) $way = 12; //提領資料不存在
              $log.= '[curl_code:'.$message['curl_code'].'] [response_code:'.$curl_response['code'].'] => '.$message['curl_message'].'['.$curl_response['message'].']';
          }
          else $log.= '[curl_code:'.$message['curl_code'].'] => '.$message['curl_message'];
      }
      else $log.= '=> '.$msg['message'];
  }
  else $log.= '=> '.$msg['message'];

  return $r;
}


function put_errlog($type, $log, $wdata) {  ## /export/home/webuser/c2c/log/ppwd/201812/ppwd_error_14.log
  global $_e;

  $t = gettimeofday();
  $dt = date("Y-m-d H:i:s", $t[sec]); //$t[usec]
  $dtu = $dt." ".substr($t[usec],0,3);
  list($yy, $mm, $dd, $hh, $ii, $ss) = preg_split("|[^\d]|", $dt);

  $dir = "/export/home/webuser/c2c/log/ppwd/$yy$mm";
  if (!is_dir($dir) && !mkdir($dir, FILE_MODE, true)) $dir = "export/home/webuser/c2c/log";
  $err_file = $dir."/ppwd_error_$dd.log";

  if ($type != 'OK') error_log("[".$dtu."]\tno:".$wdata[no]."\tmid:".$wdata[mid]."\t[".$log."]\n", 3, $err_file); ##收集全部錯誤log

  if ($type && preg_match("/^[0-9]+$/", $wdata['no'])) { ##update type & log
    $sql_cmd = ($type != 'OK')? "and pp_wd_status != 'OK' " : "";
    $sql_log = (trim($log))? "err_log = err_log + '$log' ," : "";
    $sql = "update ".$_e["_or_u"]."c_pp_wd set pp_wd_type = '$type', ".$sql_log." upd_date = sysdate where pp_wd_no = '".$wdata[no]."' ".$sql_cmd;
    $ora = ora_connect();
    ora_sql_exec($sql, $ora);
  }

  return;
}

function put_waitlog($log){ ## /export/home/webuser/c2c/data/ppwd/recheck.txt
  $file = "/export/home/webuser/c2c/data/ppwd/recheck.txt";
  error_log($log."\n", 3, $file); ##收集等待再次查詢的提領編號
}

//計算各寶提領金額 (A:個賣 S:銀特連 C:雲通寶)
function make_wd_amt($amt, $dps) { //amt:提領金額, dps:各寶可提金額
  global $wallet_sort, $wallet_rate;
  if (!$amt) return false;
  if (!$dps['A']) $dps['A'] = $amt; #金額不足個賣代墊
  $len = strlen($wallet_sort);
  $unpay = $amt;
  $run = 0;

  while($unpay && $run<2) {
    for($i=0; $i<$len; $i++) {
      if ($unpay <= 0) break;
      $w = $wallet_sort[$i]; #A,S,C
      if ($dps[$w] <= 0) continue;
      $rate = $wallet_rate[$w]; #比例

      if ($run) {
        $cut = ($unpay <= $dps[$w])? $unpay : $dps[$w];
      }
      else {
        $amount = ceil($amt*$rate);  #按比例計算
        $diff = $dps[$w]-$amount;
        $cut = ($diff >= 0)? $amount : $dps[$w];
      }

      $res[$w]+=$cut;
      $dps[$w]-=$cut;
      $unpay-=$cut;
    }
    $run++;
  }
  return $res;
}


function ssdb_pop() {
  global $_e;

  $qname = 'PP_WD@';
  $db_key = 'db1';
  $db_host = '10.100.103.51:8888';
  $rtry = 3;

  while($rtry) {
    try{
      $dao = ssdbDAO::getInstance($db_key, $db_host);
      break;
    }
    catch(Exception $e){
      $errcode = $e->getCode();
      $errmsg = $e->getMessage();
      ##寫log
      if ($errcode <= 19) {  #參數錯誤=>停止
        return false;
      }
      elseif ($errcode <= 29) { #建立新連線不成功(ssdb內部已retry5次)=>retry
        ##sleep & retry
      }
      elseif ($errcode <= 39) { #重新連線不成功(ssdb內部已retry5次)=>retry
        ##sleep & retry
      }
    }
    if (--$rtry) sleep(3);
  }
  return $dao->qpop($qname); ## false=出錯, null=沒有資料
}

function ssdb_push($item) {
  global $_e;

  $qname = 'PP_WD@';
  $db_key = 'db1';
  $db_host = '10.100.103.51:8888';
  $rtry = 3;

  while($rtry) {
    try{
      $dao = ssdbDAO::getInstance($db_key, $db_host);
      break;
    }
    catch(Exception $e){
      $errcode = $e->getCode();
      $errmsg = $e->getMessage();
      ##寫log
      if ($errcode <= 19) {
        return false;
      }
      elseif ($errcode <= 29) {
        ##sleep & retry
      }
      elseif ($errcode <= 39) {
        ##sleep & retry
      }
    }
    if (--$rtry) sleep(3);
  }
  return $dao->qpush($qname, $item); ## false=出錯
}

/*
function send_sms($sms_msg) {
  $phone_list = array("0933135121");
  $phone_str = implode(",", $phone_list);
  sms($phone_str, $sms_msg);
}
*/

exit;

?>
© 2018 GitHub, Inc.
Terms
Privacy
Security
Status
Help
Contact GitHub
Pricing
API
Training
Blog
About
Press h to open a hovercard with more details.