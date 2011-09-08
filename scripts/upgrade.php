#!/usr/bin/env php
<?php
/*
 * StatusNet - a distributed open-source microblogging tool
 * Copyright (C) 2008-2011 StatusNet, Inc.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

define('INSTALLDIR', realpath(dirname(__FILE__) . '/..'));

$shortoptions = 'x::';
$longoptions = array('extensions=');

$helptext = <<<END_OF_UPGRADE_HELP
php upgrade.php [options]
Upgrade database schema and data to latest software

END_OF_UPGRADE_HELP;

require_once INSTALLDIR.'/scripts/commandline.inc';

function main()
{
    updateSchemaCore();
    updateSchemaPlugins();

    // These replace old "fixup_*" scripts

    fixupNoticeRendered();
    fixupNoticeConversation();
    initConversation();
    initInbox();
    fixupGroupURI();
    initLocalGroup();
    initNoticeReshare();
}

function tableDefs()
{
	$schema = array();
	require INSTALLDIR.'/db/core.php';
	return $schema;
}

function updateSchemaCore()
{
    printfnq("Upgrading core schema...");

    $schema = Schema::get();
    $schemaUpdater = new SchemaUpdater($schema);
    foreach (tableDefs() as $table => $def) {
        $schemaUpdater->register($table, $def);
    }
    $schemaUpdater->checkSchema();

    printfnq("DONE.\n");
}

function updateSchemaPlugins()
{
    printfnq("Upgrading plugin schema...");

    Event::handle('CheckSchema');

    printfnq("DONE.\n");
}

function fixupNoticeRendered()
{
    printfnq("Ensuring all notices have rendered HTML...");

    $notice = new Notice();

    $notice->whereAdd('rendered IS NULL');
    $notice->find();

    while ($notice->fetch()) {
        $original = clone($notice);
        $notice->rendered = common_render_content($notice->content, $notice);
        $notice->update($original);
    }

    printfnq("DONE.\n");
}

function fixupNoticeConversation()
{
    printfnq("Ensuring all notices have a conversation ID...");

    $notice = new Notice();
    $notice->whereAdd('conversation is null');
    $notice->orderBy('id'); // try to get originals before replies
    $notice->find();

    while ($notice->fetch()) {
        try {
            $cid = null;
    
            $orig = clone($notice);
    
            if (empty($notice->reply_to)) {
                $notice->conversation = $notice->id;
            } else {
                $reply = Notice::staticGet('id', $notice->reply_to);

                if (empty($reply)) {
                    $notice->conversation = $notice->id;
                } else if (empty($reply->conversation)) {
                    $notice->conversation = $notice->id;
                } else {
                    $notice->conversation = $reply->conversation;
                }
	
                unset($reply);
                $reply = null;
            }

            $result = $notice->update($orig);

            $orig = null;
            unset($orig);
        } catch (Exception $e) {
            printv("Error setting conversation: " . $e->getMessage());
        }
    }

    printfnq("DONE.\n");
}

function fixupGroupURI()
{
    printfnq("Ensuring all groups have an URI...");

    $group = new User_group();
    $group->whereAdd('uri IS NULL');

    if ($group->find()) {
        while ($group->fetch()) {
            $orig = User_group::staticGet('id', $group->id);
            $group->uri = $group->getUri();
            $group->update($orig);
        }
    }

    printfnq("DONE.\n");
}

function initConversation()
{
    printfnq("Ensuring all conversations have a row in conversation table...");

    $notice = new Notice();
    $notice->query('select distinct notice.conversation from notice '.
                   'where notice.conversation is not null '.
                   'and not exists (select conversation.id from conversation where id = notice.conversation)');

    while ($notice->fetch()) {

        $id = $notice->conversation;

        $uri = common_local_url('conversation', array('id' => $id));

        // @fixme db_dataobject won't save our value for an autoincrement
        // so we're bypassing the insert wrappers
        $conv = new Conversation();
        $sql = "insert into conversation (id,uri,created) values(%d,'%s','%s')";
        $sql = sprintf($sql,
                       $id,
                       $conv->escape($uri),
                       $conv->escape(common_sql_now()));
        $conv->query($sql);
    }

    printfnq("DONE.\n");
}

function initInbox()
{
    printfnq("Ensuring all users have an inbox...");

    $user = new User();
    $user->whereAdd('not exists (select user_id from inbox where user_id = user.id)');
    $user->orderBy('id');

    if ($user->find()) {

        while ($user->fetch()) {

            try {
                $notice = new Notice();

                $notice->selectAdd();
                $notice->selectAdd('id');
                $notice->joinAdd(array('profile_id', 'subscription:subscribed'));
                $notice->whereAdd('subscription.subscriber = ' . $user->id);
                $notice->whereAdd('notice.created >= subscription.created');

                $ids = array();

                if ($notice->find()) {
                    while ($notice->fetch()) {
                        $ids[] = $notice->id;
                    }
                }

                $notice = null;

                $inbox = new Inbox();
                $inbox->user_id = $user->id;
                $inbox->pack($ids);
                $inbox->insert();
            } catch (Exception $e) {
                printv("Error initializing inbox: " . $e->getMessage());
            }
        }
    }

    printfnq("DONE.\n");
}

function initLocalGroup()
{
    printfnq("Ensuring all local user groups have a local_group...");

    $group = new User_group();
    $group->whereAdd('NOT EXISTS (select group_id from local_group where group_id = user_group.id)');
    $group->find();

    while ($group->fetch()) {
        try {
            // Hack to check for local groups
            if ($group->getUri() == common_local_url('groupbyid', array('id' => $group->id))) {
                $lg = new Local_group();

                $lg->group_id = $group->id;
                $lg->nickname = $group->nickname;
                $lg->created  = $group->created; // XXX: common_sql_now() ?
                $lg->modified = $group->modified;

                $lg->insert();
            }
        } catch (Exception $e) {
            printfv("Error initializing local group for {$group->nickname}:" . $e->getMessage());
        }
    }

    printfnq("DONE.\n");
}

function initNoticeReshare()
{
    printfnq("Ensuring all reshares have the correct verb and object-type...");
    
    $notice = new Notice();
    $notice->whereAdd('repeat_of is not null');
    $notice->whereAdd('(verb != "'.ActivityVerb::SHARE.'" OR object_type != "'.ActivityObject::ACTIVITY.'")');

    if ($notice->find()) {
        while ($notice->fetch()) {
            try {
                $orig = Notice::staticGet('id', $notice->id);
                $notice->verb = ActivityVerb::SHARE;
                $notice->object_type = ActivityObject::ACTIVITY;
                $notice->update($orig);
            } catch (Exception $e) {
                printfv("Error updating verb and object_type for {$notice->id}:" . $e->getMessage());
            }
        }
    }

    printfnq("DONE.\n");
}

main();