<?php

require 'MusixIDManager.php';
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

    # check for cached token

    $cached_token = $this->mc->get('MUSIX_BEARER_TOKEN');
    $cached_user_id = $this->mc->get('MUSIX_USER_ID');

    $last_auth = $this->mc->get('MUSIX_LAST_AUTH_DATE');
    $now = new DateTime("now"); // new DateTime("now");

    if ($last_auth && $cached_token && $cached_user_id) {
      $last_auth_date = DateTime::createFromFormat('Y-m-d', $last_auth);
      $interval = $now->diff($last_auth_date, true);

      if ($interval->format('%a') < 21) {
        # we have a token

        error_log("Token valid, skipping auth");

        return;
      }
    }

    list($user_id, $mbox_token) = $this->login();

    $this->mc->set('MUSIX_BEARER_TOKEN', $mbox_token);
    $this->mc->set('MUSIX_USER_ID', $user_id);
    $this->mc->set('MUSIX_LAST_AUTH_DATE', $now->format('Y-m-d'));

    error_log("Logged in.");
  }

  private function postRequest($url, $data) {
    $headers = array(
      'User-Agent' => 'Musix/27000 (iPhone; iOS 9.2; Scale/2.00)',
      'Content-Type' => 'application/json',
      'Accept' => 'application/json',
      'Accept-Language' => 'en-US;q=1, he-US;q=0.9',
      'Accept-Encoding' => 'gzip, deflate');

    $response = Requests::post($url, $headers, json_encode($data));

    return json_decode($response->body, True);
  }

  private function login() {

    $url = 'https://login.pelephone.co.il/api/GetTokenByUserPassword';
    # TODO get user/pass from Sonos interface
    $data = array(
      'USER' => $_ENV['MUSIX_USER'],
      'PASSWORD' => $_ENV['MUSIX_PASS'],
      'ApplicationID' => '274');
    
    $parsed = $this->postRequest($url, $data);

    $first_user_token = $parsed["UserToken"];

    $url = 'https://login.pelephone.co.il/api/Auth2User';
    $data = array(
      'UserToken' => $first_user_token,
      'Register' => 'N',
      'ApplicationID' => '274');

    $parsed = $this->postRequest($url, $data);

    $second_user_token = $parsed["UserToken"];
    $access_token = $parsed["AccessToken"];

    $url = 'http://musix-api.mboxltd.com/auth/Account/LogOn';
    $data = array(
      'UserToken' => $second_user_token,
      'AccessToken' => $access_token
    );

    $parsed = $this->postRequest($url, $data);

    error_log("Login done. UserId: " . $parsed["UserId"]);

    return [$parsed["UserId"], $parsed["mBoxUserToken"]];
  }

    

private function mmdFromTracks($tracks) {
        
        $mediaMD = array();

        foreach ($tracks as $track) {
            if (isset($track["Song"])) {
              $track = $track["Song"];
            }
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

    private function mcFromAlbums($artist, $albums) {

        $mediaColl = array();
        
        foreach ($albums as $album) {
            $mediaColl[] = $this->mcEntryFromAlbum($artist, $album);
        }
        
        $result = new StdClass();
        $result->index = 0;
        $result->total = count($mediaColl);
        $result->count = count($mediaColl);
        $result->mediaCollection = $mediaColl;

        return $result;
      }

      private function mcFromPlaylists($playlists) {

        $mediaColl = array();
        
        foreach ($playlists as $playlist) {
            $mediaColl[] = $this->mcEntryFromPlaylist($playlist);
        }
        
        $result = new StdClass();
        $result->index = 0;
        $result->total = count($mediaColl);
        $result->count = count($mediaColl);
        $result->mediaCollection = $mediaColl;

        return $result;
      }

      private function mmdEntryFromTrack($track) {
        $item_id = MusixIDManager::getMCTrackID($track['ID'], $track['MediaFileName']);

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
        $result = array('itemType' => 'artist',
                        'id'       => 'ARTIST:' . $artist['ID'],
                        'artistid' => 'ARTIST:' . $artist['ID'],
                        'title'    => $artist['Name'],
                        'albumArtURI' => $artist['ImageURL']);

        return $result;
    }

      private function mcEntryFromAlbum($artist, $album) {
        
        $album_id = MusixIDManager::getAlbumID($album['ID'], $artist['ID']);

        $result = array('itemType'     => 'album',
                        'id'           => $album_id,
                        'title'        => $album['Name'],
                        'artist'       => $artist['Name'],
                        'canPlay'      => true,
                        'canEnumerate' => true,
                        'canCache'     => true);

        $result['albumArtURI']  = $album['ImageURL'];
        
        // ExtendedMetadata has to set artistId and albumId
        $result['artistId'] = MusixIDManager::getArtistID($artist['ID']);
        $result['albumId'] = $album_id;

        return $result;
    }

    private function mcEntryFromPlaylist($playlist) {
        
        $playlist_id = MusixIDManager::getPlaylistID($playlist['ID'], $playlist['IsSocialNetworkShare']);

        $playlist_name = $playlist['Name'];
        if (!isset($playlist['Name']) || (strlen($playlist_name) == 0)) {
          $playlist_name = 'NONAME';
        }

        $result = array('itemType'     => 'playlist',
                        'id'           => $playlist_id,
                        'title'        => $playlist_name,
                        'canPlay'      => true,
                        'canEnumerate' => true);

        return $result;
    }
    

    private function musixAlbumTracks($id)
    {
      $url = 'http://musix-simplay.s3-eu-west-1.amazonaws.com/Customers/13/Data/Albums/album_'.$id.'.json';

      return $this->getJSONURL($url)['Tracks'];
    }


    private function musixPlaylistTracks($id, $user_guid, $is_public_playlist)
    {
      if ($is_public_playlist) {
        $url = 'http://musix-simplay.s3-eu-west-1.amazonaws.com/Customers/13/Data/Playlists/playlist_'.$id.'.json';
      } else {
        $url = 'http://musix-simplay.s3.amazonaws.com/Customers/13/Users/' . strtoupper($user_guid) . '/Playlist_' . $id . '.json';
      }

      return $this->getJSONURL($url)['Items'];
    }


    private function musixArtistAlbums($id)
    {
      $url = 'http://musix-simplay.s3-eu-west-1.amazonaws.com/Customers/13/Data/Artists/artist_'.$id.'.json';
      $items = $this->getJSONURL($url);

      return [$items['Artist'], $items['Albums']];
    }

    private function musixMyPlaylists($user_guid)
    {
      $url = 'http://musix-simplay.s3-eu-west-1.amazonaws.com/Customers/13/Users/' . strtoupper($user_guid) . '/Playlists.json';

      return $this->getJSONURL($url)['Items'];
    }

    public function getPlaylistTracks($id)
    {
      list($a, $playlist_id, $is_public_playlist) = MusixIDManager::breakPlaylistID($id, True);
      $tracks = $this->musixPlaylistTracks($playlist_id, $this->mc->get('MUSIX_USER_ID'), $is_public_playlist);
      return $this->mmdFromTracks($tracks);
    }

    public function getArtistAlbumsMetadata($id)
    {
      list($artist, $albums) = $this->musixArtistAlbums($id);
      return $this->mcFromAlbums($artist, $albums);
    }

    public function getAlbumMetadata($id)
    {
      $tracks = $this->musixAlbumTracks($id);
      return $this->mmdFromTracks($tracks);
    }

    private function getJSONURL($url) {
      $resp = Requests::get($url);
      $json = json_decode(MusixAPI::removeBOM($resp->body), True);
      return $json;
    }


  private function musixSearch($term, $type)
  {
    $url = 'http://musix-api.mboxltd.com/search/SolrSearch/SearchItem?type=' .
      $type . '&paramType=1&is_exact=0&term=' . $term . '&size=100';

    return $this->getJSONURL($url);
  }

  private function musixSearchTracks($term)
  {
    return $this->musixSearch($term, 0)["Songs"];
  }

  private function musixSearchArtists($term)
  {
    return $this->musixSearch($term, 2)["Artists"];
  }

  public function getMyPlaylists() {
    $playlists = $this->musixMyPlaylists($this->mc->get('MUSIX_USER_ID'));
    return $this->mcFromPlaylists($playlists);
  }

  public function searchTracks($term) {
    $tracks = $this->musixSearchTracks($term);
    return $this->mmdFromTracks($tracks);
  }

  public function searchArtists($term) {
    $artists = $this->musixSearchArtists($term);
    return $this->mcFromArtists($artists);
  }

  public function getMediaMetadata($mediaID)
  {
    return $this->mc->get($mediaID);
  }

  public function getMediaURL($trackid) {
      list($str, $id, $fname) = MusixIDManager::breakMCTrackID($trackid);

      $url = 'http://musix-api.mboxltd.com/tokens/GetToken';
      // TODO do proper auth
      $headers = array(
        'mBoxUserToken' => $this->mc->get('MUSIX_BEARER_TOKEN'),
        'User-Agent' => 'Musix/27000 (iPhone; iOS 9.2; Scale/2.00)',
        'Content-Type' => 'application/json',
        'Cookie' => 'ai_user=' . $_ENV['MUSIX_USER_COOKIE'],
        'Accept' => 'application/json',
        'Accept-Language' => 'en-US;q=1, he-US;q=0.9',
        'Accept-Encoding' => 'gzip, deflate');

      $data = array(
        'SongId' => $id,
        'UserId' => $this->mc->get('MUSIX_USER_ID'),
        'MediaFileName' => $fname,
        'ServiceId' => '13');

      $response = Requests::post($url, $headers, json_encode($data));

      $parsed = json_decode($response->body, True);

      return $parsed["URL"];
    }

    private static function removeBOM($data) {
      if (0 === strpos(bin2hex($data), 'efbbbf')) {
         return substr($data, 3);
      }

      return $data;
  }

}


?>