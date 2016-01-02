<?php

class MusixAPI
{
  function __construct() 
  {
     
  }

//require_once '/app/vendor/rmccue/requests/library/Requests.php'; Requests::register_autoloader();


  private function mmdEntryFromTrack($track) {
        return array('itemType' => 'track',
             'id'       => 'TRACK:' . $track['ID'],
             'title'    => $track['Name'],
             'mimeType' => 'audio/mp3',
             'trackMetadata' =>
               array('artist'      => $track['Artist'],
                     'artistId'    => "ARTIST:" . $track['ArtistId'],
                     'album'       => $track['Album'],
                     'albumId'     => "ALBUM:" . $track['AlbumId'],
                     'duration'    => 30, // $track['Duration'],
                     'index'       => $track['ID'],
                     'canPlay'     => true,
                     'canSkip'     => true)
             );
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

  private function searchTracks($term)
  {
    $resp = Requests::get(
      'http://musix-api.mboxltd.com/search/SolrSearch/SearchItem?type=0&paramType=1&is_exact=0&term='.
        $term.'&size=100');

    $tracks = json_decode($resp->body, True);

    return $tracks["Songs"];
  }

  public function search($term) {
    $tracks = $this->searchTracks($term);
    return $this->mmdFromTracks($tracks);
  }

}


?>