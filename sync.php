#!/usr/bin/php
<?PHP

chdir(dirname(__FILE__));

require_once('config.php');

$array = get_urls_from_mail(IMAP_FOLDER);
if(IMAP_FOLDER_DELETE) {
  $array = array_merge($array, get_urls_from_mail(IMAP_FOLDER_DELETE, 1));
}

$i = 0;
if(count($array)) {
  foreach($array as $tosend) {

    // BACKUP URL
    flog("Saving " . $tosend[3]);
    $bk_link = backup_url($tosend);
    flog("Saved");
    if(!strstr($tosend[4], $tosend[3])) { // add orig url to comment
      $tosend[4] .= "  \n" . $tosend[3];
    }
    $tosend[5] = $tosend[3]; // save orig url to csv
    $tosend[3] = $bk_link; // direct url becomes bk url

    flog("Sending to Shaarli");
    $ret = shaarli_post(SHAARLI_URL, SHAARLI_SECRET, $tosend); 
    flog("Shaarli: $ret");

    $fp = fopen(CSV_FILE, "a"); 
    fputcsv($fp, $tosend);
    fclose($fp);

    $i++;
  }

}
flog("Sent $i urls to Shaarli.");
