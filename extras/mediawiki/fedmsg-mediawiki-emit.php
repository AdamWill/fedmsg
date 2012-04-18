<?php
/*
 * fedmsg-mediawiki-emit.php
 * -------------------------
 *
 * A MediaWiki plugin that emits messages to the Fedora Infrastructure Message
 * Bus.
 *
 * Installation Instructions
 * -------------------------
 *
 * You need to enable the following in /etc/php.ini for this to fly:
 *
 *    extension=zmq.so
 *
 * To install the plugin for mediawiki, you need to copy this file to:
 *
 *    /usr/share/mediawiki/fedmsg-mediawiki-emit.php
 *
 * And you also need to enable it by adding the following to the bottom of
 * /var/www/html/wiki/LocalSettings.php
 *
 *    require_once("$IP/fedmsg-mediawiki-emit.php");
 *
 * This corner of the fedmsg topology requires that an instance of fedmsg-relay
 * be running somewhere.  The reason being that multiple php processes get run
 * by apache at the same time which would necessitate `n` different bind
 * addresses for each process.  Here, as opposed to 'normal' fedmsg
 * configurations in our python webapps, each php process actively connects to
 * the relay and emits messages.  Our 'normal' python webapps establish a
 * passive endpoint on which they broadcast messages.
 *
 * Miscellaneous
 * -------------
 *
 * Version:   0.2.0
 * License:   LGPLv2+
 * Author:    Ralph Bean
 * Source:    http://github.com/ralphbean/fedmsg
 */

if (!defined('MEDIAWIKI')) {echo("Cannot be run outside MediaWiki"); die(1);}

$wgHooks['ArticleSaveComplete'][] = 'article_save';

// globals
$config = 0;
$queue = 0;

function initialize() {
  global $config, $queue;
  /* Load the config.  Create a publishing socket. */

  // Danger! Danger!
  // TODO - change this to just shell_exec("fedmsg-config")
  $json = shell_exec("fedmsg-logger --print-config");
  $config = json_decode($json, true);

  /* Just make sure everything is sane with the fedmsg config */
  if (!array_key_exists('relay_inbound', $config)) {
    echo("fedmsg-config has no 'relay_inbound'");
    die(1);
  }

  $context = new ZMQContext(1, true);
  $queue = $context->getSocket(ZMQ::SOCKET_PUB, "pub-a-dub-dub");
  $queue->connect($config['relay_inbound']);
}

initialize();

function emit_message($subtopic, $message) {
  global $queue;

  # Re-implement some of the logc from fedmsg/core.py
  # We'll have to be careful to keep this up to date.
  #
  # TODO -- pull the 'dev' string out of a mediawiki config variable
  $prefix = "org.fedoraproject.dev.wiki.";
  $topic = $prefix . $subtopic;

  $envelope = json_encode(array(
    topic => $topic,
    msg => $message,
    timestamp => time(),
  ));

  $queue->send($topic, ZMQ::MODE_SNDMORE);
  $queue->send($envelope);
}

function article_save(
  &$article,
  &$user,
  $text,
  $summary,
  $minoredit,
  $watchthis,
  $sectionanchor,
  &$flags,
  $revision,
  &$status,
  $baseRevId,
  &$redirect
) {
  $topic = "article.edit";
  $msg = array(
    "user" => $user->getName(),
    "title" => $article->getTitle()->getText(),
  );
  emit_message($topic, $msg);
  return true;
}

?>
