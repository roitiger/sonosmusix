# Sonos-Musix integration

Based on sample code: http://musicpartners.sonos.com/sites/default/files/MusicBrainzPHP.tar.gz

Modified to work with Musix - http://www.musix.co.il

Supported functionality: Search artist/track/playlist, browse artist/album, play tracks.

# Setup

- Set up heroku app
- Add Memcache Cloud add on
- Set credentials: heroku config:set "MUSIX_USER=YOUR_MUSIX_USER" "MUSIX_PASS=YOUR_MUSIX_PASS"
- Add the service to Sonos system: http://musicpartners.sonos.com/docs?q=node/134

# TODO 

- Move user/pass to be set in the Sonos interface instead of Heroku config var
- Is cookie env var required?