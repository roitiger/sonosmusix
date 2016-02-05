# sonosmusix

Original sample code: http://musicpartners.sonos.com/sites/default/files/MusicBrainzPHP.tar.gz

Modified to bridge with Musix - http://www.musix.co.il

Supported functionality: Search artist/track/playlist, browse artist/album, play tracks.

Setup:

set up heroku app

add memcache add on

# TODO move user/pass to be set in the Sonos interface

heroku config:set "MUSIX_USER=<user>" "MUSIX_PASS=<pass>"


Sonos system setup:

http://musicpartners.sonos.com/docs?q=node/134