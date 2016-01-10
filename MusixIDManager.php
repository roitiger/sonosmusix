<?php

class MusixIDManager
{
  public static function getMCTrackID($id, $fname) 
  {
    return 'TRACK:' . $id . ':' . $fname;
  }

  // Returns TRACK, <id>, <media_filename>
  public static function breakMCTrackID($trackid) 
  {
    return explode(":", $trackid, 3);
  }

  public static function getArtistID($id) 
  {
    return 'ARTIST:' . $id;
  }

  // Returns ARTIST, <id>
  public static function breakArtistID($artistid) 
  {
    return explode(":", $artistid, 2);
  }

  public static function getPlaylistID($id, $is_public_playlist) 
  {
    return 'PLAYLIST:' . ($is_public_playlist ? 'pub' : 'prv') . $id;
  }

  // Returns PLAYLIST, <id>, <is_public_playlist>
  public static function breakPlaylistID($playlistid, $no_header = False) 
  {
    if ($no_header) {
      $id = $playlistid;
      $a = 'PLAYLIST';
    } else {
      list($a, $id) = explode(":", $playlistid, 2);
    }
    $is_public_playlist = (substr($id, 0, 3) == 'pub');
    $id = substr($id, 3);
    
    return [$a, $id, $is_public_playlist];
  }

  public static function getAlbumID($album_id, $artist_id) 
  {
    return 'ALBUM:' . $album_id . ':' . $artist_id;
  }

  // Returns ALBUM, <album_id>, <artist_id>
  public static function breakAlbumID($albumid) 
  {
    return explode(":", $albumid, 3);
  }
}


?>