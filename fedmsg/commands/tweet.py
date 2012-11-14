# This file is part of fedmsg.
# Copyright (C) 2012 Red Hat, Inc.
#
# fedmsg is free software; you can redistribute it and/or
# modify it under the terms of the GNU Lesser General Public
# License as published by the Free Software Foundation; either
# version 2.1 of the License, or (at your option) any later version.
#
# fedmsg is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
# Lesser General Public License for more details.
#
# You should have received a copy of the GNU Lesser General Public
# License along with fedmsg; if not, write to the Free Software
# Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA
#
# Authors:  Ralph Bean <rbean@redhat.com>
#

import time

import twitter as twitter_api
import bitlyapi

import fedmsg
import fedmsg.text
from fedmsg.commands import command


@command(name="fedmsg-tweet", extra_args=[], daemonizable=True)
def tweet(**kw):
    """ Rebroadcast messages to twitter """

    # First, sanity checking.
    if not 'tweet_settings' in kw and not 'statusnet_settings' in kw:
        raise ValueError("Not configured to tweet.")

    # Boilerplate..
    kw['publish_endpoint'] = None
    kw['name'] = 'relay_inbound'
    kw['mute'] = True

    # Set up fedmsg
    fedmsg.init(**kw)
    fedmsg.text.make_processors(**kw)

    apis = []
    # Set up twitter if configured
    settings = kw.get('tweet_settings', [])
    if settings:
        apis.append(twitter_api.Api(**settings))

    # Set up statusnet if configured
    settings = kw.get('statusnet_settings', [])
    if settings:
        apis.append(twitter_api.Api(**settings))

    # Set up bitly
    settings = kw['bitly_settings']
    bitly = bitlyapi.BitLy(
        settings['api_user'],
        settings['api_key'],
    )

    # How long to sleep if we spew too fast.
    hibernate_duration = kw['tweet_hibernate_duration']
    # Sleep a second or two inbetween messages to try and avoid the hibernate
    intermessage_pause = kw['tweet_intermessage_pause']

    def _post_to_api(api, message):
        try:
            api.PostUpdate(message)
        except Exception as e:
            if 'Too many notices too fast;' in str(e):
                # Cool our heels then try again.
                print "Sleeping for", hibernate_duration
                time.sleep(hibernate_duration)
                _post_to_api(api, message)
            elif 'duplicate' in str(e):
                # Let it slide ...
                pass
            else:
                raise

    for name, ep, topic, msg in fedmsg.tail_messages(**kw):
        message = fedmsg.text.msg2subtitle(msg, **kw)
        link = fedmsg.text.msg2link(msg, **kw)

        if link:
            link = bitly.shorten(longUrl=link)['url']
            message = (message[:139] + " ")[:139 - len(link)] + link
        else:
            message = message[:140]

        print("Tweeting %r" % message)
        for api in apis:
            _post_to_api(api, message)

        time.sleep(intermessage_pause)
