<?php

function getCountryCode_and_phone_number($code,$phone_number){

$ccodes = array(
  "AF" => 93,"AX" => 358,"AL" => 355,"DZ" => 213,"AS" => 1684,"AD" => 376,"AO" => 244,"AI" => 1264,"AQ" => 672,"AG" => 1268,"AR" => 54,"AM" => 374,"AW" => 297,"AU" => 61,"AT" => 43,"AZ" => 994,"BS" => 1242,"BH" => 973,"BD" => 880,"BB" => 1246,"BY" => 375,"BE" => 32,"BZ" => 501,"BJ" => 229,"BM" => 1441,"BT" => 975,"BO" => 591,"BQ" => 599,"BA" => 387,"BW" => 267,"BV" => 55,"BR" => 55,"IO" => 246,"BN" => 673,"BG" => 359,"BF" => 226,"BI" => 257,"KH" => 855,"CM" => 237,"CA" => 1,"CV" => 238,"KY" => 1345,"CF" => 236,"TD" => 235,"CL" => 56,"CN" => 86,"CX" => 61,"CC" => 672,"CO" => 57,"KM" => 269,"CG" => 242,"CD" => 242,"CK" => 682,"CR" => 506,"CI" => 225,"HR" => 385,"CU" => 53,"CW" => 599,"CY" => 357,"CZ" => 420,"DK" => 45,"DJ" => 253,"DM" => 1767,"DO" => 1809,"EC" => 593,"EG" => 20,"SV" => 503,"GQ" => 240,"ER" => 291,"EE" => 372,"ET" => 251,"FK" => 500,"FO" => 298,"FJ" => 679,"FI" => 358,"FR" => 33,"GF" => 594,"PF" => 689,"TF" => 262,"GA" => 241,"GM" => 220,"GE" => 995,"DE" => 49,"GH" => 233,"GI" => 350,"GR" => 30,"GL" => 299,"GD" => 1473,"GP" => 590,"GU" => 1671,"GT" => 502,"GG" => 44,"GN" => 224,"GW" => 245,"GY" => 592,"HT" => 509,"HM" => 0,"VA" => 39,"HN" => 504,"HK" => 852,"HU" => 36,"IS" => 354,"IN" => 91,"ID" => 62,"IR" => 98,"IQ" => 964,"IE" => 353,"IM" => 44,"IL" => 972,"IT" => 39,"JM" => 1876,"JP" => 81,"JE" => 44,"JO" => 962,"KZ" => 7,"KE" => 254,"KI" => 686,"KP" => 850,"KR" => 82,"XK" => 383,"KW" => 965,"KG" => 996,"LA" => 856,"LV" => 371,"LB" => 961,"LS" => 266,"LR" => 231,"LY" => 218,"LI" => 423,"LT" => 370,"LU" => 352,"MO" => 853,"MK" => 389,"MG" => 261,"MW" => 265,"MY" => 60,"MV" => 960,"ML" => 223,"MT" => 356,"MH" => 692,"MQ" => 596,"MR" => 222,"MU" => 230,"YT" => 262,"MX" => 52,"FM" => 691,"MD" => 373,"MC" => 377,"MN" => 976,"ME" => 382,"MS" => 1664,"MA" => 212,"MZ" => 258,"MM" => 95,"NA" => 264,"NR" => 674,"NP" => 977,"NL" => 31,"AN" => 599,"NC" => 687,"NZ" => 64,"NI" => 505,"NE" => 227,"NG" => 234,"NU" => 683,"NF" => 672,"MP" => 1670,"NO" => 47,"OM" => 968,"PK" => 92,"PW" => 680,"PS" => 970,"PA" => 507,"PG" => 675,"PY" => 595,"PE" => 51,"PH" => 63,"PN" => 64,"PL" => 48,"PT" => 351,"PR" => 1787,"QA" => 974,"RE" => 262,"RO" => 40,"RU" => 7,"RW" => 250,"BL" => 590,"SH" => 290,"KN" => 1869,"LC" => 1758,"MF" => 590,"PM" => 508,"VC" => 1784,"WS" => 684,"SM" => 378,"ST" => 239,"SA" => 966,"SN" => 221,"RS" => 381,"CS" => 381,"SC" => 248,"SL" => 232,"SG" => 65,"SX" => 721,"SK" => 421,"SI" => 386,"SB" => 677,"SO" => 252,"ZA" => 27,"GS" => 500,"SS" => 211,"ES" => 34,"LK" => 94,"SD" => 249,"SR" => 597,"SJ" => 47,"SZ" => 268,"SE" => 46,"CH" => 41,"SY" => 963,"TW" => 886,"TJ" => 992,"TZ" => 255,"TH" => 66,"TL" => 670,"TG" => 228,"TK" => 690,"TO" => 676,"TT" => 1868,"TN" => 216,"TR" => 90,"TM" => 7370,"TC" => 1649,"TV" => 688,"UG" => 256,"UA" => 380,"AE" => 971,"GB" => 44,"US" => 1,"UM" => 1,"UY" => 598,"UZ" => 998,"VU" => 678,"VE" => 58,"VN" => 84,"VG" => 1284,"VI" => 1340,"WF" => 681,"EH" => 212,"YE" => 967,"ZM" => 260,"ZW" => 263);

$countryArr = array(
  "AD" => 9,"AE" => 9,"AF" => 9,"AG" => 10,"AI" => 10,"AL" => 9,"AM" => 8,"AN" => 7,"AO" => 9,"AQ" => 0,"AR" => 10,"AS" => 7,"AT" => 12,"AU" => 9,"AW" => 7,"AX" => 0,"AZ" => 9,"BA" => 8,"BB" => 10,"BD" => 10,"BE" => 10,"BF" => 8,"BG" => 9,"BH" => 8,"BI" => 8,"BJ" => 8,"BL" => 10,"BM" => 10,"BN" => 7,"BO" => 8,"BQ" => 10,"BR" => 11,"BS" => 7,"BT" => 8,"BV" => 0,"BW" => 8,"BY" => 9,"BZ" => 7,"CA" => 10,"CC" => 6,"CD" => 9,"CF" => 8,"CG" => 9,"CH" => 9,"CI" => 8,"CK" => 5,"CL" => 9,"CM" => 9,"CN" => 11,"CO" => 10,"CR" => 8,"CS" => 8,"CU" => 8,"CV" => 7,"CW" => 7,"CX" => 6,"CY" => 8,"CZ" => 9,"DE" => 11,"DJ" => 8,"DK" => 8,"DM" => 10,"DO" => 10,"DZ" => 9,"EC" => 9,"EE" => 9,"EG" => 10,"EH" => 9,"ER" => 7,"ES" => 9,"ET" => 9,"FI" => 10,"FJ" => 6,"FK" => 5,"FM" => 7,"FO" => 6,"FR" => 9,"GA" => 8,"GB" => 10,"GD" => 10,"GE" => 9,"GF" => 9,"GG" => 6,"GH" => 9,"GI" => 8,"GL" => 6,"GM" => 7,"GN" => 8,"GP" => 9,"GQ" => 9,"GR" => 10,"GS" => 0,"GT" => 8,"GU" => 7,"GW" => 8,"GY" => 7,"HK" => 8,"HM" => 0,"HN" => 8,"HR" => 9,"HT" => 8,"HU" => 9,"ID" => 11,"IE" => 9,"IL" => 9,"IM" => 6,"IN" => 10,"IO" => 7,"IQ" => 10,"IR" => 10,"IS" => 7,"IT" => 10,"JE" => 6,"JM" => 7,"JO" => 9,"JP" => 10,"KE" => 9,"KG" => 9,"KH" => 9,"KI" => 5,"KM" => 7,"KN" => 10,"KP" => 10,"KR" => 10,"KW" => 8,"KY" => 7,"KZ" => 10,"LA" => 8,"LB" => 8,"LC" => 10,"LI" => 8,"LK" => 9,"LR" => 8,"LS" => 8,"LT" => 9,"LU" => 9,"LV" => 8,"LY" => 9,"MA" => 9,"MC" => 9,"MD" => 8,"ME" => 9,"MF" => 10,"MG" => 9,"MH" => 7,"MK" => 9,"ML" => 8,"MM" => 8,"MN" => 8,"MO" => 8,"MP" => 7,"MQ" => 9,"MR" => 9,"MS" => 10,"MT" => 8,"MU" => 8,"MV" => 7,"MW" => 9,"MX" => 10,"MY" => 9,"MZ" => 9,"NA" => 9,"NC" => 6,"NE" => 8,"NF" => 5,"NG" => 10,"NI" => 8,"NL" => 9,"NO" => 8,"NP" => 10,"NR" => 7,"NU" => 4,"NZ" => 8,"OM" => 8,"PA" => 8,"PE" => 9,"PF" => 6,"PG" => 7,"PH" => 10,"PK" => 10,"PL" => 9,"PM" => 9,"PN" => 6,"PR" => 10,"PS" => 9,"PT" => 9,"PW" => 7,"PY" => 9,"QA" => 8,"RE" => 9,"RO" => 9,"RS" => 9,"RU" => 10,"RW" => 9,"SA" => 9,"SB" => 7,"SC" => 7,"SD" => 9,"SE" => 9,"SG" => 8,"SH" => 4,"SI" => 8,"SJ" => 6,"SK" => 9,"SL" => 8,"SM" => 10,"SN" => 9,"SO" => 8,"SR" => 7,"SS" => 9,"ST" => 8,"SV" => 8,"SX" => 8,"SY" => 9,"SZ" => 9,"TC" => 7,"TD" => 8,"TF" => 0,"TG" => 8,"TH" => 9,"TJ" => 9,"TK" => 5,"TL" => 7,"TM" => 8,"TN" => 8,"TO" => 5,"TR" => 10,"TT" => 7,"TV" => 5,"TW" => 9,"TZ" => 9,"UA" => 9,"UG" => 9,"UM" => 0,"US" => 10,"UY" => 9,"UZ" => 9,"VA" => 9,"VC" => 10,"VE" => 7,"VG" => 10,"VI" => 10,"VN" => 10,"VU" => 7,"WF" => 6,"WS" => 7,"XK" => 8,"YE" => 9,"YT" => 9,"ZA" => 9,"ZM" => 9,"ZW" => 9 );

// Get an array of country codes in lowercase for case-insensitive comparison.
$array_ccodes = array_map('strtolower', array_keys($ccodes));

// Find the index of the user-input country code in the array.
$index = array_search(strtolower($code), $array_ccodes);

if ($index !== false) {
    // Retrieve the actual country code using the index found.
    $ccodeKeys = array_keys($ccodes);
    $country_code = $ccodeKeys[$index];
    $country_value = $ccodes[$country_code];
    // echo "Country Code: " . $country_value . "\n";
}
 else {
    // echo "Country code not found.\n";
  $country_value = '';
}

$phone_number = str_replace(" ", "", $phone_number);
$phone_number = str_replace('(', '', $phone_number);
$phone_number = str_replace(')', '', $phone_number);
$phone_number = str_replace('-', '', $phone_number);
$phone_number = str_replace('+', '', $phone_number);
$phone_number = substr($phone_number, -$countryArr[$code]);
// echo "Phone Number : " . $phone_number . "\n";


   
  $CountrycodeAndPhoneNumber = array("country_code" => $country_value, "phone_number" => $phone_number);

  return $CountrycodeAndPhoneNumber;


}
?>
