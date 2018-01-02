<?php

function get_page_title($url) {
    $fp = file_get_contents($url);
    if (!$fp)
        return null;

    $res = preg_match("/<title>(.*)<\/title>/siU", $fp, $title_matches);
    if (!$res)
        return null;

    // Clean up title: remove EOL's and excessive whitespace.
    $title = preg_replace('/\s+/', ' ', $title_matches[1]);
    $title = trim($title);
    return $title;
}

function base64url_encode($data) {
  return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function generateToken($secret) {
    $header = base64url_encode('{
        "typ": "JWT",
        "alg": "HS512"
    }');
    $payload = base64url_encode('{
        "iat": '. time() .'
    }');
    $signature = base64url_encode(hash_hmac('sha512', $header .'.'. $payload , $secret, true));
    return $header . '.' . $payload . '.' . $signature;
}

function shaarli_getInfo($baseUrl, $secret) {
    $token = generateToken($secret);
    $endpoint = rtrim($baseUrl, '/') . '/api/v1/info';

    $headers = [
        'Content-Type: text/plain; charset=UTF-8',
        'Authorization: Bearer ' . $token,
    ];

    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_AUTOREFERER, 1);
    curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);

    $result = curl_exec($ch);
    curl_close($ch);

    return $result;
}

function shaarli_post($baseUrl, $secret, $tosend) {
    $token = generateToken($secret);
    $endpoint = rtrim($baseUrl, '/') . '/api/v1/links';

    $tags = array('email', $tosend[5]);

    $image = '';
    if(stristr($tosend[3], '.jpg') || stristr($tosend[3], '.jpeg') || 
       stristr($tosend[3], '.png') || stristr($tosend[3], '.gif')) {
      $tags[] = 'image';
    }

    $data = [
      'url' => $tosend[3],
      'title' => $tosend[0],
      'description' => $tosend[4],
      'tags' => $tags,
      'private' => false  
    ];
    $json = json_encode($data);

    $headers = [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($json),
        'Authorization: Bearer ' . $token,
    ];


    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_AUTOREFERER, 1);
    curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');                                                                     
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json);                                                                  

    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}

function shaarli_test() {
  var_dump(shaarli_getInfo(SHAARLI_URL, SHAARLI_SECRET));
  exit;
}

function flog($message) {
  $now_time = date("Ymd G:i:s");
  $t = file_put_contents(LOG_FILE, "$now_time - $message\n", FILE_APPEND);
  print "$now_time - $message\n";
}

function backup_url($tosend) {
  $url = $tosend[3];
  $title = $tosend[0];

  $archive_dir = BACKUP_DIR . '/' . date('Y');
  if(!file_exists($archive_dir)) {
    mkdir($archive_dir);
  }

  $archive_dir .= '/' . date('m');

  if(!file_exists($archive_dir)) {
    mkdir($archive_dir);
  }


  $odir = getcwd();
  chdir($archive_dir);
  $cmd = "wget wget -E -H -k -K -p -e robots=off $url";
  $t = Execute($cmd);
  chdir($odir);

  $bk_link = BACKUP_URL . str_replace(BACKUP_DIR, '', $archive_dir);
  $link_path = '/' . str_replace('http://', '', str_replace('https://', '', $url)); 

  if(!file_exists($archive_dir . $link_path)) {
    $link_path .= '.html';
  }
  if(!file_exists($archive_dir . $link_path) || strstr($link_path, '?')) {
    $link_path = dirname($link_path);
  }

  $bk_link .= $link_path;

  return($bk_link);
}

function Execute($command) {

  $command .= ' 2>&1';
  $handle = popen($command, 'r');
  $log = '';

  while (!feof($handle)) {
    $line = fread($handle, 1024);
    $log .= $line;
  }
  pclose($handle);

  return $log;
}

function get_urls_from_mail($folder, $delete = 0) {
  flog('Checking mail: ' . $folder);
  $mbox = imap_open ("{" . IMAP_HOST . IMAP_PATH . "}" . $folder, IMAP_USER, IMAP_PASS);
  if($delete) {
  $emails = imap_search($mbox, 'ALL');
  }
  else {
    $emails = imap_search($mbox, IMAP_SEARCH); // UNSEEN - ALL
  }
  if(!is_array($emails)) {
    flog('No messages');
    return(array());
  }
  flog(count($emails) . ' new message(s). ' . ($delete ? '(Will Delete)' : ''));

  $array = array();
  $i = 0;
  if($emails) {
    $output = '';
	
    rsort($emails);
	
    $i = 0;
    foreach($emails as $email_number) {

      $urls = array();

      imap_clearflag_full($mbox, $email_number, "\\Seen \\Flagged", ST_UID);
		
      /* get information specific to this email */
      $overview = imap_fetch_overview($mbox,$email_number,0);
      $structure = imap_fetchstructure($mbox, $email_number);
      $message = '';

      if(isset($structure->parts) && is_array($structure->parts) && isset($structure->parts[1])) {
        $part = $structure->parts[1];

        $message = imap_fetchbody($mbox,$email_number,2);

        if($part->encoding == 3) {
            $message = imap_base64($message);
        } else if($part->encoding == 1) {
            $message = imap_8bit($message);
        } else {
            $message = imap_qprint($message);
        }
     }

      $message = html_entity_decode($message);
      $message = preg_replace('/\<br(\s*)?\/?\>/i', "\n", $message); 
      $message = strip_tags($message);

      $subject = '';
      if(isset($overview[0]->subject)) {
        $subject = imap_utf8($overview[0]->subject);
      }

      $when = '';
      if(isset($overview[0]->date)) {
        $when = $overview[0]->date;
      }

/*
      $output.= ($overview[0]->seen ? 'read' : 'unread') . "\n";
      $output.= "Subject: " . $overview[0]->subject . "\n";
      $output.= 'When: ' . $overview[0]->date . "\n";
      $output.= 'Message: ' . $message . "\n";
      print $output;
*/

      preg_match_all('#\bhttps?://[^\s()<>]+(?:\([\w\d]+\)|([^[:punct:]\s]|/))#', $subject . ' ' . $message, $matches);

      if(isset($matches[0])) {
        $urls = $matches[0];
      }

      $urls = array_values(array_unique(array_filter($urls)));

      if(count($urls)) {
        foreach($urls as $url) {
          flog("Saw: $url");

          if(!$subject || $subject == $url) {
            $subject = get_page_title($url);
          }
 
          if(trim($message) == trim($url)) {
            $message = '';
          }

          $array[] = array($subject, $when, date('Y-m-d G:i:s'), $url, $message, $folder);
        }
      }
      else {

      }

      imap_delete($mbox, $email_number);

      $i++;
    }
  } 

  if($delete) {
    imap_expunge($mbox);
  }
  /* close the connection */
  imap_close($mbox);
  flog(count($array) . " URLs seen.");
  return($array);
}

