<?php

require_once '/app/vendor/rmccue/requests/library/Requests.php'; 
Requests::register_autoloader();

class MusixAPI
{
  private $mc;

  function __construct() 
  {
    $this->mc = new Memcached();
    $this->mc->setOption(Memcached::OPT_BINARY_PROTOCOL, true);
    $this->mc->addServers(array_map(function($server) { return explode(':', $server, 2); }, explode(',', $_ENV['MEMCACHEDCLOUD_SERVERS'])));
    $this->mc->setSaslAuthData($_ENV['MEMCACHEDCLOUD_USERNAME'], $_ENV['MEMCACHEDCLOUD_PASSWORD']);
     
  }

//require_once '/app/vendor/rmccue/requests/library/Requests.php'; Requests::register_autoloader();

  private function getMCTrackID($id, $fname) 
  {
    return 'TRACK:' . $id . ':' . $fname;
  }

  // Returns TRACK, <id>, <media_filename>
  private function breakMCTrackID($trackid) 
  {
    return explode(":", $trackid, 3);
  }

  private function mmdEntryFromTrack($track) {
        $item_id = $this->getMCTrackID($track['ID'], $track['MediaFileName']);

        // Sometimes duration doesnt have the hours
        $duration = $track['Duration'];
        if (substr_count($duration, ':') == 1) {
          $duration = "00:" . $duration; 
        }

        list($h,$m,$s) = explode(":",$duration);
        $duration_sec = $s + ($m * 60) + ($h * 60 * 60);

        $info = array('itemType' => 'track',
             'id'       => $item_id,
             'title'    => $track['Name'],
             'mimeType' => 'audio/mp3',
             'trackMetadata' =>
               array('artist'      => $track['Artist'],
                     'artistId'    => "ARTIST:" . $track['ArtistId'],
                     'album'       => $track['Album'],
                     'albumId'     => "ALBUM:" . $track['AlbumId'],
                     'duration'    => $duration_sec,
                     'index'       => $track['ID'],
                     'canPlay'     => true,
                     'canSkip'     => true,
                     'albumArtURI' => $track['ImageURL'])
             );
        $this->mc->set($item_id, $info);

        return $info;
    }

    function mcEntryFromArtist($artist) {
        
        logMsg(1, "mcEntryFromArtist: id: " . $artist['ID'] . " : " . $artist['Name']);

        $result = array('itemType' => 'artist',
                        'id'       => 'ARTIST:' . $artist['ID'],
                        'artistid' => 'ARTIST:' . $artist['ID'],
                        'title'    => $artist['Name'],
                        'albumArtURI' => $artist['ImageURL']);

        return $result;
    }

    

private function mmdFromTracks($tracks) {
        
        $mediaMD = array();

        foreach ($tracks as $track) {
            $mediaMD[] = $this->mmdEntryFromTrack($track);
        }
        
        $result = new StdClass();
        $result->index = 0; //$tracks['index'];
        $result->total = count($mediaMD);//$tracks['total'];
        $result->count = count($mediaMD);
        $result->mediaMetadata = $mediaMD;

        return $result;
    }
    

    function mcFromArtists($artists) {
        // This grabs a list of artists from the backend and converts it to a MediaCollection.
        $mediaColl = array();
        
        foreach ($artists as $artist) {            
            $mediaColl[] = $this->mcEntryFromArtist($artist);
        }
        
        $result = new StdClass();
        $result->index = 0;
        $result->total = count($mediaColl);
        $result->count = count($mediaColl);
        $result->mediaCollection = $mediaColl;

        return $result;
    }

  private function musixSearch($term, $type)
  {
    $resp = Requests::get(
      'http://musix-api.mboxltd.com/search/SolrSearch/SearchItem?type='.$type.'&paramType=1&is_exact=0&term='.
        $term.'&size=100');

    $items = json_decode($resp->body, True);

    return $items;
  }

  private function musixSearchTracks($term)
  {
    return $this->musixSearch($term, 0)["Songs"];
  }

  private function musixSearchArtists($term)
  {
    return $this->musixSearch($term, 2)["Artists"];
  }

  public function searchTracks($term) {
    $tracks = $this->musixSearchTracks($term);
    return $this->mmdFromTracks($tracks);
  }

  public function searchArtists($term) {
    $artists = $this->searchArtists($term);
    return $this->mcFromArtists($artists);
  }

  public function getMediaMetadata($mediaID)
  {
    return $this->mc->get($mediaID);
  }

  public function getMediaURL($trackid) {
      list($str, $id, $fname) = $this->breakMCTrackID($trackid);

      $url = 'http://musix-api.mboxltd.com/tokens/GetToken';
      // TODO do proper auth
      $headers = array(
        'mBoxUserToken' => $_ENV['MUSIX_BEARER_TOKEN'],
        'User-Agent' => 'Musix/27000 (iPhone; iOS 9.2; Scale/2.00)',
        'Content-Type' => 'application/json',
        'Cookie' => 'ai_user=' . $_ENV['MUSIX_USER_COOKIE'],
        'Accept' => 'application/json',
        'Accept-Language' => 'en-US;q=1, he-US;q=0.9',
        'Accept-Encoding' => 'gzip, deflate');

      $data = array(
        'SongId' => $id,
        'UserId' => $_ENV['MUSIX_USER_ID'],
        'MediaFileName' => $fname,
        'ServiceId' => '13');

      $response = Requests::post($url, $headers, json_encode($data));

      $parsed = json_decode($response->body, True);

      return $parsed["URL"];
    }

}


?>