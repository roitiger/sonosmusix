<?php

include 'lib/localizer.php';
include 'lib/backend.php';
include 'MusixAPI.php'

/////////////////////////////////////////////////////////////////////////////
//
// Localization helper
//

$localizer = new SimpleLocalizer("l10n");

function l10n($id) {
    global $localizer;
    return $localizer->translate($id);
}

/////////////////////////////////////////////////////////////////////////////
//
// Prevent anything other than POST
//
if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    echo "<br>" . l10n("MSG_SERVER_FOR_USE_BY_SONOS_PRODUCTS_ONLY")."</br>\n";
    exit;
}

logMsg(0,"---------------REQUEST POST START-----------");
foreach ($_POST as $key => $value) {
    logMsg(0,"key = " . $key . "value = " . $value); 
}
logMsg(0,"---------------REQUEST GET START-----------");
foreach ($_GET as $key => $value) {
    logMsg(0,"key = " . $key . "value = " . $value); 
}
logMsg(0,"---------------REQUEST HEADER END-----------");

/////////////////////////////////////////////////////////////////////////////
//
// Set some global debug flags
//
$logLevel = 3;
foreach ($_GET as $key => $value) {
    if ($key == "log") {
        $logLevel = (int)$value;
        logMsg(0, "logLevel=$logLevel");
    }
}

/////////////////////////////////////////////////////////////////////////////
//
// Logging
//
function getLogFilePath()
{
    return "/tmp/SonosAPI.log";
}

function logMsg($level,$msg)
{
    global $logLevel;
    
    //if ($level <= $logLevel) {
    //    error_log($msg . "\r\n", 3, getLogFilePath());
    //}
    // Heroku logging
    error_log($msg);
}

/////////////////////////////////////////////////////////////////////////////
//
// Main class

// Pull in all of the backend support

class SonosAPI
{
    private $sessionid;
    private $username;
    private $user;
    private $defaultAlbumArtURI;
    
    private $catalog;
    private $usermgr;
    private $favorites;
    private $ratings;

    private $musix;

    /////////////////////////////////////////////////////////////////////////////
    //
    // Constructor
    //
    
    function SonosAPI()
    {
        // FIXME: This all needs to go into a single backend class that wraps the details.        
        $this->catalog   = new SimpleCatalog("database/brainz.sqlite");
        $this->usermgr   = new SimpleUserManager("database/users.sqlite");
        $this->favorites = new SimpleFavorites("database/favorites.sqlite");
        $this->ratings   = new SimpleRatings("database/ratings.sqlite");        

        // NOTE: We only have one image, so grab the path here
        $this->defaultAlbumArtURI = $this->getMediaBaseURL() . "album.jpg";

        $this->$musix = new MusixAPI();
    }

    /////////////////////////////////////////////////////////////////////////////
    //
    // credentials
    //
    //   Verify that session id is present in the SOAP header.  While you can
    //   theoretically support username/password auth, Sonos has deprecated
    //   this.
    //
    //   As of October, 2011, we are sending a credentials entity in the SOAP
    //   header for getSessionId requests. Thus we can't count on the
    //   sessionid being there.
    //
    //   So, if we're being called by getSessionId() we act a little different.
    //
    //   <disclaimer>I don't pretend to be a PHP programmer. If there's a better
    //   way, make it happen.</disclaimer>
    
    function credentials($args) {

	if (isset($args->deviceId)) {
            $this->deviceid = $args->deviceId;
	}

        if (isset($args->deviceProvider)) {
            $this->deviceProvider = $args->deviceProvider;
        }

        logMsg(1, "deviceid=" . $this->deviceid . ", deviceProvider=" . $this->deviceProvider);

        if (isset($args->sessionId)) {            
            $this->sessionid = $args->sessionId;
            $this->user      = $this->usermgr->getUser($this->sessionid);

            logMsg(1, "sessionid=" . $this->sessionid . ", user=" . $this->user);
        
            if ($this->user == "") {
                throw new SoapFault('Client.SessionIdInvalid', l10n(MSG_SOAPFAULT_SESSION_ID_INVALID));
            }
       }
    }
    
    /////////////////////////////////////////////////////////////////////////////
    //
    // getSessionId
    //
    
    function getSessionId($args) {

        logMsg(0, "getSessionId: username=" . $args->username);

        $sessionid = $this->usermgr->getSessionId($args->username, $args->password);

        if (! $sessionid) {
            throw new SoapFault('Client.LoginUnauthorized',
                                l10n('MSG_SOAPFAULT_LOGIN_UNAUTHORIZED'));            
        }
        
        logMsg(0, "getSessionId:  sid=" . $sessionid);

        return array( 'getSessionIdResult' => $sessionid);
    }

    private function getID($id) {
        return explode(":",$id,4);
    }

    // KLUDGE: There must be an easier way...
    private function getMediaBaseURL() {
        
        $url = "http://";
        
        if ($_SERVER["SERVER_PORT"] != "80") {
            $url .= $_SERVER["SERVER_NAME"].":".$_SERVER["SERVER_PORT"];
        } else {
            $url .= $_SERVER["SERVER_NAME"];
        }
        
        $parts = parse_url($_SERVER["REQUEST_URI"]);
        $url  .= substr($parts['path'],0,strrpos($parts['path'],'/'));
        $url  .= "/media/";
        
        return $url;
    }    
    
    /////////////////////////////////////////////////////////////////////////////
    //
    // Metadata
    //
    //

    function getMetadata($args) {
        
        logMsg(0, "getMetadata: " . $args->id);

        // Strip off the first word before ':'
        $idarray      = $this->getID($args->id);        
        $args->prefix = strtoupper(array_shift($idarray));
        $args->id     = array_shift($idarray);

        // Is there a getMD function for it?
        $func = "getMD_" . $args->prefix;
        logMsg(0, "$func: " . $args->id);

        if (!method_exists($this,$func)) {
            throw new SoapFault('Server.ItemNotFound',
                                l10n("MSG_SOAPFAULT_ITEM_NOT_FOUND") . ": $origid");
        }
        
        return array('getMetadataResult' => $this->$func($args));
    }
    
    function getMD_ROOT($args) {
        
        $result = new StdClass();
        
        $mediaColl = array();
        $result->index = 0;

        // This media collection is the root browse menu.
        $mediaColl[] = array('itemType' => 'favorites',
                             'id' => 'FAVORITES',
                             'title' => l10n("MSG_BROWSE_FAVORITES"));
        
        $mediaColl[] = array('itemType' => 'collection',
                             'id' => 'STAFF',
                             'title' => l10n("MSG_BROWSE_STAFF"));
        
        $mediaColl[] = array('itemType' => 'collection',
                             'id' => 'CATALOG',
                             'title' => l10n("MSG_BROWSE_CATALOG"));
        
        $result->total = $result->count = count($mediaColl);
        $result->mediaCollection = $mediaColl;      
        return  $result;
    }
    
    function getMD_SEARCH($args) {
        
        $result = new StdClass();
        
        // This media collection is the search menu.
        
        $mediaColl = array();
        $mediaColl[] = array('itemType' => 'search',
                             'id' => 'SART',
                             'title' => l10n("MSG_ARTISTS"));
        
        $mediaColl[] = array('itemType' => 'search',
                             'id' => 'SALB',
                             'title' => l10n("MSG_ALBUMS"));
        
        $mediaColl[] = array('itemType' => 'search',
                             'id' => 'STRK',
                             'title' => l10n("MSG_TRACKS"));
        
        $result->index = 0;
        $result->count = $result->total = count($mediaColl);
        $result->mediaCollection = $mediaColl;
        
        return $result;
    }

    function mcEntryFromArtist($artist) {
        
        logMsg(1, "mcEntryFromArtist: id: " . $artist['id'] . " : " . $artist['name']);

        $result = array('itemType' => 'artist',
                        'id'       => 'ARTIST:' . $artist['id'],
                        'title'    => $artist['name']);

        // ExtendedMetadata sometimes returns album art for an artist
        if (isset($artist['albumart'])) {
            $result['albumArtURI'] = $artist['albumart'];
        }

        // ExtendedMetadata has to set artistId in addition to id in order to
        // enable "Browse the Artist".
        if (isset($artist['artistid'])) {
            $result['artistId'] = "ARTIST:" . $artist['artistid'];
        }
        
        return $result;
    }
    
    function mcFromArtists($artists) {

        $result = new StdClass();
        $result->index = $artists['index'];
        $result->total = $artists['total'];

        // This grabs a list of artists from the backend and converts it to a MediaCollection.
        $mediaColl = array();
        
        foreach ($artists['data'] as $artist) {            
            $mediaColl[] = $this->mcEntryFromArtist($artist);
        }
        
        $result->count = count($mediaColl);
        $result->mediaCollection = $mediaColl;

        return $result;
    }

    function mcEntryFromAlbum($album) {
        
        logMsg(1, "mcEntryFromAlbum: id: " . $album['id']);
        
        $result = array('itemType'     => 'album',
                        'id'           => 'ALBUM:' . $album['id'],
                        'title'        => $album['title'],
                        'artist'       => $album['artist'],
                        'canPlay'      => true,
                        'canEnumerate' => true,
                        'canCache'     => true);

        if (isset($album['albumart'])) {
            $result['albumArtURI']  = $album['albumart'];
        } else {
            $result['albumArtURI']  = $this->defaultAlbumArtURI;
        }
        
        // ExtendedMetadata has to set artistId and albumId
        if (isset($album['artistid'])) {
            $result['artistId'] = "ARTIST:" . $album['artistid'];
        }
        
        if (isset($album['albumid'])) {
            $result['albumId'] = "ALBUM:" . $album['albumid'];
        }

        return $result;
    }
    
    function mcFromAlbums($albums) {

        $result = new StdClass();
        $result->index = $albums['index'];
        $result->total = $albums['total'];

        $mediaColl = array();

        foreach ($albums['data'] as $album) {
            $mediaColl[] = $this->mcEntryFromAlbum($album);
        }
        
        $result->count = count($mediaColl);
        $result->mediaCollection = $mediaColl;

        return $result;
    }

    function mmdEntryFromTrack($track) {

        logMsg(1, "mmdFromTrack: id: " . $track['id']);
        
        if (!isset($track['albumart'])) {
            $track['albumart']  = $this->defaultAlbumArtURI;
        }
        
        $dynamic = array();
        $dynamic[] = array('name' => "isStarred", 
                           'value' => $this->ratings->getRating($this->user, 'TRACK:' . $track['id'])
                          );
        $dynamic[] = array('name' => 'isRead', 
                           'value' => "true"
                          );
        
        return array('itemType' => 'track',
                     'id'       => 'TRACK:' . $track['id'],
                     'title'    => $track['title'],
                     'mimeType' => 'audio/mp3',
                     'trackMetadata' =>
                     array('artist'      => $track['artist'],
                           'artistId'    => "ARTIST:" . $track['artistid'],
                           'album'       => $track['album'],
                           'albumId'     => "ALBUM:" . $track['albumid'],
                           'duration'    => 30, // $track['duration'],
                           'rating'      => $this->ratings->getRating($this->user, $track['id']),
                           'index'       => $track['id'],
                           'canPlay'     => true,
                           'canSkip'     => true,
                           'albumArtURI' => $track['albumart']),
                           'dynamic'     => $dynamic
                     );
    }

    function mmdFromTracks($tracks) {

        $result = new StdClass();
        $result->index = $tracks['index'];
        $result->total = $tracks['total'];
        
        $mediaMD = array();

        foreach ($tracks['data'] as $track) {
            $mediaMD[] = $this->mmdEntryFromTrack($track);
        }
        
        $result->count = count($mediaMD);
        $result->mediaMetadata = $mediaMD;

        return $result;
    }

    function getMD_STAFF($args) {        
        $favorites = $this->catalog->browseStaffFavorites($args->index, $args->count);
        return $this->mcFromArtists($favorites);        
    }

    function getMD_CATALOG($args) {
        
        $result = new StdClass();

        // This MediaCollection is the menu that pops up when the user hits the
        // "Entire Catalog" entry on the root menu.
        //
        $mediaColl = array();
        $result->index = 0;

        $mediaColl[] = array('itemType' => 'collection',
                             'id' => 'CATALOG_ARTISTS',
                             'title' => l10n("MSG_ARTISTS"));
        
        $mediaColl[] = array('itemType' => 'collection',
                             'id' => 'CATALOG_ALBUMS',
                             'title' => l10n("MSG_ALBUMS"));
        
        $mediaColl[] = array('itemType' => 'playlist',
                             'id' => 'CATALOG_TRACKS',
                             'title' => l10n("MSG_TRACKS"),
                             'canEmumerate' => true,
                             'canPlay' => true);
        
        $result->total = $result->count = count($mediaColl);
        $result->mediaCollection = $mediaColl;      
        return  $result;

    }
    
    function getMD_CATALOG_ARTISTS($args) {
        $artists = $this->catalog->searchArtist("",$args->index,$args->count);
        return $this->mcFromArtists($artists);
    }
    
    function getMD_CATALOG_ALBUMS($args) {
        $albums = $this->catalog->searchAlbum("",$args->index,$args->count);
        return $this->mcFromAlbums($albums);        
    }
    
    function getMD_CATALOG_TRACKS($args) {
        $tracks = $this->catalog->searchTrack("",$args->index,$args->count);
        return $this->mmdFromTracks($tracks);
    }

    /////////////////////////////////////////////////////////////////////////////
    //
    //    
    function getMD_FAVORITES($args) {

        $result = new StdClass();

        // This MediaCollection is the menu that pops up when the user hits the
        // "My Library" entry on the root menu.
        //
        $mediaColl = array();
        $result->index = 0;

        $mediaColl[] = array('itemType' => 'collection',
                             'id' => 'FAV_ARTISTS',
                             'title' => l10n("MSG_ARTISTS"));
        
        $mediaColl[] = array('itemType' => 'collection',
                             'id' => 'FAV_ALBUMS',
                             'title' => l10n("MSG_ALBUMS"));
        
        $mediaColl[] = array('itemType' => 'playlist',
                             'id' => 'FAV_TRACKS',
                             'title' => l10n("MSG_TRACKS"),
                             'canEmumerate' => true,
                             'canPlay' => true);
        
        $result->total = $result->count = count($mediaColl);
        $result->mediaCollection = $mediaColl;      
        return  $result;
    }

    function getMD_FAV_ARTISTS($args) {
    
        $result = new StdClass();

        $mediaColl = array();
        $result->index = 0;

        // Grab the GIDs for all of the favorite albums for this user
        $ids = $this->favorites->getFavorites("artists", $this->user);

        foreach ($ids as $id) {
            $data = $this->catalog->getArtistInfo($id);
            $mediaColl[] = $this->mcEntryFromArtist($data);
        }
        
        $result->total = $result->count = count($mediaColl);
        $result->mediaCollection = $mediaColl;      
        return  $result;
    }
    
    function getMD_FAV_ALBUMS($args) {
        
        $result = new StdClass();
        
        $mediaColl = array();
        $result->index = 0;

        // Grab the GIDs for all of the favorite albums for this user
        $ids = $this->favorites->getFavorites("albums", $this->user);

        foreach ($ids as $id) {
            $data = $this->catalog->getAlbumInfo($id);
            $mediaColl[] = $this->mcEntryFromAlbum($data);
        }
        
        $result->total = $result->count = count($mediaColl);
        $result->mediaCollection = $mediaColl;      
        return  $result;
    }

    function getMD_FAV_TRACKS($args) {
    
        $result = new StdClass();

        $mediaMD = array();
        $result->index = 0;

        // Grab the GIDs for all of the favorite tracks for this user
        $ids = $this->favorites->getFavorites("tracks", $this->user);

        foreach ($ids as $id) {
            $data = $this->catalog->getTrackInfo($id);
            $mediaMD[] = $this->mmdEntryFromTrack($data);
        }
        
        $result->total = $result->count = count($mediaMD);
        $result->mediaMetadata = $mediaMD;
        return  $result;
    }

    function getMD_ARTIST($args) {        
        $albums = $this->catalog->browseArtist($args->id,$args->index,$args->count);
        return $this->mcFromAlbums($albums);
    }
    
    function getMD_ALBUM($args) {        
        $tracks = $this->catalog->browseAlbum($args->id,$args->index,$args->count);
        return $this->mmdFromTracks($tracks);
    }
    
    /////////////////////////////////////////////////////////////////////////////
    //
    // Search
    //
    function search($args) {
         
        $id    = strtoupper($args->id);        
        $term  = $args->term;
        $index = $args->index;
        $count = $args->count;

        $result = "";

        if ($id == "ARTIST") {
            
            $artists = $this->catalog->searchArtist($term,$index,$count);
            $result  = $this->mcFromArtists($artists);
            
        } elseif ($id == "ALBUM") {

            $albums = $this->catalog->searchAlbum($term,$index,$count);
            $result = $this->mcFromAlbums($albums);
            
        } elseif ($id == "TRACK") {

            $tracks = $this->catalog->searchTrack($term,$index,$count);
            $result = $this->mmdFromTracks($tracks);
            
        } else {
            
            throw new SoapFault('Server.ItemNotFound', l10n("MSG_SOAPFAULT_ITEM_NOT_FOUND")." (search: $id:$term)");
            
        }
        
        return array('searchResult' => $result);
    }
    
    /////////////////////////////////////////////////////////////////////////////
    //
    // getMediaMetadata
    //    
    function getMediaMetadata($args) {
        
        logMsg(0, "getMediaMetadata: " . $args->id);
        
        $idarray      = $this->getID($args->id);        
        $args->prefix = strtoupper(array_shift($idarray));
        $id           = array_shift($idarray);
        
        $tracks = $this->catalog->browseTrack($id);
        
        foreach ($tracks['data'] as $track) {
            return array('getMediaMetadataResult' => $this->mmdEntryFromTrack($track));
        }
        
        throw new SoapFault('Client.ItemNotFound', l10n("MSG_SOAPFAULT_ITEM_NOT_FOUND"));
    }

    function getMediaURI($args) {
        logMsg(0, "getMediaURI: " . $args->id);
        $url = $this->getMediaBaseURL() . "music.mp3";
        return array('getMediaURIResult' => $url);
    }

    function getExtendedMetadata($args) {

        logMsg(0, "getExtendedMetadata: " . $args->id);

        // Strip off the first word before ':' (convention in this script)
        $args->fullid = $args->id;
        $idarray      = $this->getID($args->id);        
        $args->prefix = strtoupper(array_shift($idarray));
        $args->id     = array_shift($idarray);

        // Is there a getMD function for it?
        $func = "getXMD_" . $args->prefix;        
        logMsg(1, "$func: " . $args->id);
        
        if (!method_exists($this,$func)) {
            throw new SoapFault('Server.ItemNotFound', l10n("MSG_SOAPFAULT_ITEM_NOT_FOUND") . ": $origid");
        }
        
        return array('getExtendedMetadataResult' => $this->$func($args));
    }

    function getXMD_ARTIST($args) {

        // Hit the backend to get artist info 
        $artist = $this->catalog->getArtistInfo($args->id);

        // Set a different image for artists.  Production SW may or may not
        // have this.
        $artist['albumart'] = $this->getMediaBaseURL() . "artist.jpg";
        
        // Package it up
        $result = new StdClass();
        $result->mediaCollection = $this->mcEntryFromArtist($artist);
                                        
        // Related text (only ARTIST_BIO is supported in this case)
        //
        // This is going to get passed to getExtendedMetadata as soon as the
        // user clicks on the "About the Artist" entry on the page.
        //
        $result->relatedText = array('id'   => "BIO:" . $args->fullid,
                                     'type' => 'ARTIST_BIO');
        
        return $result;
    }

    function getXMD_ALBUM($args) {
        
        // Hit the backend to get album info        
        $album = $this->catalog->getAlbumInfo($args->id);
        LogMsg(1, "XMD: artistid=" . $album['artistid']);
        LogMsg(1, "XMD:  albumid=" . $album['albumid']);

        // Package it up
        $result = new StdClass();
        $result->mediaCollection = $this->mcEntryFromAlbum($album);

        // Related text (only ALBUM is supported in this case)
        //
        // This is going to get passed to getExtendedMetadata as soon as the
        // user clicks on the "About the Artist" entry on the page.
        //
        $result->relatedText = array('id'   => "NOTES:". $args->fullid,
                                     'type' => 'ALBUM_NOTES');
        
        return $result;
    }

    function getXMD_TRACK($args) {

        // Hit the backend to get track info        
        $track = $this->catalog->getTrackInfo($args->id);
        $track['albumart'] = $this->getMediaBaseURL() . "album.jpg";

        // Package it up
        $result = new StdClass();
        $result->mediaMetadata = $this->mmdEntryFromTrack($track);
        
        return $result;
    }

    function getExtendedMetadataText($args) {
        logMsg(0, "getExtendedMetadataText: " . $args->id);
        $text = "Fake extended metadata for: " . $args->id;        
        return array('getExtendedMetadataTextResult' => $text);
    }

    function createItem($args) {

        LogMsg(0, "createItem: " . $args->favorite);

        $idarray      = $this->getID($args->favorite);
        $args->prefix = strtoupper(array_shift($idarray));
        $args->id     = array_shift($idarray);

        if ($args->prefix == "ARTIST") {
            $this->favorites->addFavorite("artists", $this->user, $args->id);
        } elseif ($args->prefix == "ALBUM") {
            $this->favorites->addFavorite("albums", $this->user, $args->id);
        } elseif ($args->prefix == "TRACK") {
            $this->favorites->addFavorite("tracks", $this->user, $args->id);
        } else {
            throw new SoapFault('Client.ServiceUnavailable', l10n("MSG_SOAPFAULT_SERVICE_UNAVAILABLE")." (createItem: " . $args->prefix . ")");
        }
        
        return array('createItemResult' => $args->favorite);
    }

    function deleteItem($args) {

        LogMsg(0, "deleteItem: " . $args->favorite);

        $idarray      = $this->getID($args->favorite);        
        $args->prefix = strtoupper(array_shift($idarray));
        $args->id     = array_shift($idarray);
        
        if ($args->prefix == "ARTIST") {
            $this->favorites->delFavorite("artists", $this->user, $args->id);
        } elseif ($args->prefix == "ALBUM") {
            $this->favorites->delFavorite("albums", $this->user, $args->id);
        } elseif ($args->prefix == "TRACK") {
            $this->favorites->delFavorite("tracks", $this->user, $args->id);
        } else {
            throw new SoapFault('Client.ServiceUnavailable', l10n("MSG_SOAPFAULT_SERVICE_UNAVAILABLE")." (deleteItem: " . $args->prefix . ")");
        }
    }
    
    function getScrollIndices($args) {
        $args;
        throw new SoapFault('Client.ServiceUnavailable', l10n("MSG_SOAPFAULT_SERVICE_UNAVAILABLE")." (getScrollIndices)");
    }

    function getLastUpdate($args) {
        
        $result = new StdClass();
	$result->catalog   = $this->catalog->getLastUpdate();
        $favoriteUpdateId = $this->favorites->getLastUpdate($this->user);
        $ratingsUpdateId = $this->ratings->getLastUpdate($this->user);
        // Because ratings data is part of the dynamic metadata returned
        // by getMetadata() and getExtendedMetadata(), the "favorites"
        // updateId has to include changes to the ratings DB as well as the
        // favorites DB.
        if ($favoriteUpdateId > $ratingsUpdateId) {
            $result->favorites = $favoriteUpdateId;
        } else {
            $result->favorites = $ratingsUpdateId;
        }
        $result->pollInterval = 60;
        
        logMsg(2, "getLastUpdate: user=" . $this->user . ", update=" . $result->catalog . ":" . $result->favorites);
        
        return array('getLastUpdateResult' => $result );
    }
    
    function reportStatus($args) {
        $args;
        logMsg(0, "reportStatus");
    }

    function setPlayedSeconds($args) {
        logMsg(0, "setPlayedSeconds: Played " . $args->id . " for " . $args->seconds . " seconds");
    }

    function getAccount($args) {
        $args;
        logMsg(0, "getAccount");
        throw new SoapFault('Client.LoginUnauthorized', l10n("MSG_SOAPFAULT_LOGIN_UNAUTHORIZED")." (getAccount)");
    }

    function createTrialAccount($args) {
        $args;
        logMsg(0, "createTrialAccount");
        throw new SoapFault('Client.LoginUnauthorized', l10n("MSG_SOAPFAULT_LOGIN_UNAUTHORIZED")." (createTrialAccount)");
    }

    function mergeTrialAccount($args) {
        $args;
        logMsg(0, "mergeTrialAccount");
        throw new SoapFault('Client.LoginUnauthorized', l10n("MSG_SOAPFAULT_LOGIN_UNAUTHORIZED")." (mergeTrialAccount)");
    }
    
    function rateItem($args) {
    	LogMsg(0, "rateItem: id=" . $args->id . ", rating=" . $args->rating);

    	//first add the rating
    	$this->ratings->addRating($this->user, $args->id, $args->rating);
    	
    	//now form a result
    	$result = new StdClass();
    	//this logic probably should be set in a separate function to make it easier to modify
    	$result->shouldSkip = $args->rating < 0 ? true : false;
    	$result->messageStringId = "fakeStringIDForTheRatingMessage";    	

    	return array('rateItemResult' => $result );
    }
}



/////////////////////////////////////////////////////////////////////////////
//
// Instantiate the SoapServer
//
$start = microtime(true);
$server = new SoapServer('lib/Sonos.wsdl', array('cache_wsdl' => 0));
$server->setClass('SonosAPI');


/////////////////////////////////////////////////////////////////////////////
//
// Kick off the request
//
$request = "REQUEST:";
global $requestContents;
$requestContents = "\n".file_get_contents('php://input')."\n";

LogMsg(2, $request . $requestContents);

ob_start();
try
{
    $server->handle();
}
catch (Exception $e)
{
    logMsg(0, "exception: " . $e->getMessage());

    $errorId2msgId = array (
        'Server.ServiceUnknownError'  => l10n("MSG_SOAPFAULT_SERVICE_UNKNOWN_ERROR"),
        'Server.ServiceUnavailable'   => l10n("MSG_SOAPFAULT_SERVICE_UNAVAILABLE"),
        'Client.SessionIdInvalid'     => l10n("MSG_SOAPFAULT_SESSION_ID_INVALID"),
        'Client.LoginInvalid'         => l10n("MSG_SOAPFAULT_LOGIN_UNAUTHORIZED"),
        'Client.LoginDisabled'        => l10n("MSG_SOAPFAULT_LOGIN_DISABLED"),
        'Client.LoginUnauthorized'    => l10n("MSG_SOAPFAULT_LOGIN_UNAUTHORIZED"),
        'Client.DeviceLimit'          => l10n("MSG_SOAPFAULT_DEVICE_LIMIT"),
        'Client.UnsupportedTerritory' => l10n("MSG_SOAPFAULT_UNSUPPORTED_TERRITORY"),
        'Client.ItemNotFound'         => l10n("MSG_SOAPFAULT_ITEM_NOT_FOUND"),
                            );
    $requestContents = "\n".file_get_contents('php://input')."\n"; // reset this to just the input on any fault
    
    $transittime = number_format(microtime(true)-$start,6);
    logMsg(0, $request.$requestContents."\nRESPONSE ($transittime): ERROR: ".$errorId2msgId[$e->getMessage()] . 
           ' ('.$e->getCode().': '.$e->getFile().':'.$e->getLine().")\n".
           "---------------------------------------------------\n");
    
    $server->fault($e->getMessage(), $errorId2msgId[$e->getMessage()] . 
                   ' ('.$e->getCode().': '.$e->getFile().':'.$e->getLine().')');
    // $server->fault ends processing, so this line is never reached.
}

$response = ob_get_contents();
$size = strlen($response);
$sizemsg = "$size bytes";

/////////////////////////////////////////////////////////////////////////////
//
// Encode long responses using GZIP
//
// KLUDGE: This appears to be quite broken...
//
if (0) {
    if ( function_exists("gzencode") && strpos($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip') !== false) {
        if ( strpos($_SERVER['HTTP_ACCEPT_ENCODING'], 'x-gzip') !== false) {
            $encoding = 'x-gzip';
        } else {
            $encoding = 'gzip';
        }
        if ($size > 2048) {
            $contents = gzencode($response, 9);
            $gzsize = strlen($contents);
            if ($gzsize + 50 < $size) {
                ob_end_clean();
                header('Content-Encoding: '.$encoding);
                print($contents);
                $percent=number_format($gzsize/$size*100,2);
                $sizemsg = "$size bytes gzipped to $gzsize bytes = $percent%";
            }
        }
    }
}
ob_flush();

       
$transittime = number_format(microtime(true)-$start,6);
logMsg(2,
       "\nRESPONSE ($transittime) $sizemsg:\n" . $response .
       "---------------------------------------------------\n");

?>
