<?php
$token = 'TOKEN_SCALEWAY';
$offres = [25=>'Start-2-S-SSD',100=>'Start-3-S-SSD', 999=>'Pro-9-XS'];

$optSelected = getopt('o:');

$offre = !empty($optSelected)?(int)$optSelected['o']:die('Vous devez spécifier une offre ! ');

function call_online_api($token, $http_method, $endpoint, $get = array(), $post = array())
{
    if (!empty($get)) {
        $endpoint .= '?' . http_build_query($get);
    }

    $call = curl_init();
    curl_setopt($call, CURLOPT_URL, 'https://api.online.net/api/v1' . $endpoint);
    curl_setopt($call, CURLOPT_HTTPHEADER, array('Authorization: Bearer ' . $token, 'X-Pretty-JSON: 1'));
    curl_setopt($call, CURLOPT_RETURNTRANSFER, true);

    if ($http_method == 'POST') {
        curl_setopt($call, CURLOPT_POST, true);
        curl_setopt($call, CURLOPT_POSTFIELDS, http_build_query($post));
    }

    return curl_exec($call);
}

/*
* Teste le stock de la dedibox
* Retourne l'id du datacenter en france où il y a du stock
*/

function checkStock($objet) {

 foreach($objet->{'stocks'} as $dispo) {
	if(($dispo->{'stock'} > 0) && ($dispo->{'datacenter'}->{'country'}) === 'FR') {
          return $dispo->{'datacenter'}->{'id'};
        }
 }

 return 0;

}

/* Vérifie la disponibilité chez scaleway */
$info = call_online_api($token, 'GET', '/dedibox/plans');
$info_obj = json_decode($info);

$datacenterID = checkStock($info_obj->{'Start Range'}->{$offres[$offre]})?:die('Pas de disponibilité !');

echo $datacenterID."\n".$info_obj->{'Start Range'}->{$offres[$offre]}->{'id'};

$options = call_online_api($token, 'GET', '/dedibox/options/'.$info_obj->{'Start Range'}->{$offres[$offre]}->{'id'}.'?support=basic');
$options_obj = json_decode($options);

var_dump($options_obj);



/* effectue l'achat de la dedibox */
$dedibox = call_online_api($token, 'POST', 'dedibox/', ['product'=>$info_obj->{'Start Range'}->{$offres[$offre]}->{'id'},'datacenter'=>$datacenterID,'support'=>'basic']);
var_dump(json_decode($dedibox));


$status = call_online_api($token, 'GET', '/dedibox/status/');
$status_obj = json_decode($status);


// var_dump($info_obj->{'Start Range'}->{'Start-3-S-SSD'});



