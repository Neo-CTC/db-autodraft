<?php
/**
 *
 * DB Auto Draft. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2022, Neo, https://crosstimecafe.com
 * @license       GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace crosstimecafe\dbautodraft\controller;

use phpbb\auth\auth;
use phpbb\config\config;
use phpbb\content_visibility;
use phpbb\controller\helper;
use phpbb\db\driver\driver_interface;
use phpbb\json_response;
use phpbb\language\language;
use phpbb\request\request;
use phpbb\template\template;
use phpbb\user;

/**
 * DB Auto Draft main controller.
 */
class main_controller
{
	private $helper;
	private $template;
	private $language;
	private $user;
	private $request;
	private $db;
	private $auth;
	private $json;

	/**
	 * Constructor
	 *
	 * @param \phpbb\controller\helper $helper   Controller helper object
	 * @param \phpbb\template\template $template Template object
	 * @param \phpbb\language\language $language Language object
	 * @param \phpbb\user              $user     User
	 * @param \phpbb\request\request   $request  Request
	 */
	public function __construct(helper $helper, template $template, language $language, user $user, request $request, driver_interface $db, auth $auth)
	{
		$this->helper   = $helper;
		$this->template = $template;
		$this->language = $language;
		$this->user     = $user;
		$this->request  = $request;
		$this->db       = $db;
		$this->auth     = $auth;
		$this->json     = new json_response();

		// Todo: what is content visibility and do we need it? posting.php:176
		// $this->content_visibility = $cv;
	}

	public function preview()
	{
		$err = [];

		$draft_id = $this->request->variable('d', 0);
		if ($draft_id === 0)
		{
			$err[] = 'Missing draft ID';
		}

		if (!$this->user->data['is_registered'])
		{
			$err[] = 'Not logged in';
		}

		if (!$err)
		{
			$sql    = 'SELECT * FROM ' . DRAFTS_TABLE . ' WHERE user_id = ' . $this->user->id() . ' AND draft_id = ' . $draft_id;
			$result = $this->db->sql_query($sql);
			$row    = $this->db->sql_fetchrow($result);
			$this->db->sql_freeresult($result);
			if ($row === false)
			{
				$err[] = 'Invalid draft ID';
			}
			else
			{
				$this->template->assign_vars([
					'SUBJECT' => $row['draft_subject'],
					'MESSAGE' => generate_text_for_display($row['draft_message'], '', '', 0),
					'TIME'    => $this->user->format_date($row['save_time']),
				]);
				global $phpbb_root_path, $phpEx;
				if ($row['topic_id'] != 0)
				{
					$load_url = append_sid($phpbb_root_path . 'posting.' . $phpEx, 't=' . $row['topic_id'] . '&mode=reply&d=' . $row['draft_id'], false);
				}
				else
				{
					$load_url = append_sid($phpbb_root_path . 'posting.' . $phpEx, 'f=' . $row['forum_id'] . '&mode=post&d=' . $row['draft_id'], false);
				}
				$this->template->assign_var('LOAD_LINK', $load_url);
			}
		}

		$this->template->assign_vars([
			'ERROR' => implode("<br>\n", $err),
		]);

		return $this->helper->render('@crosstimecafe_dbautodraft/dbautodraft_preview.html', 'Title?');
	}

	public function save()
	{
		// Referenced posting.php as a guide

		if (!$this->user->data['is_registered'] || !check_form_key('posting') || !$this->auth->acl_get('u_savedrafts'))
		{
			$this->json->send(['error' => true]);
			return;
		}

		// Assign variables
		$forum_id = $this->request->variable('f', 0);
		$topic_id = $this->request->variable('t', 0);
		$post_id  = $this->request->variable('p', 0);
		$mode     = $this->request->variable('mode', '');
		$draft_id = $this->request->variable('d', 0);

		// Nowhere to put draft
		if ($topic_id === 0 && $forum_id === 0 && $post_id === 0)
		{
			$this->json->send(['error' => true]);
			return;
		}

		// Find forum & topic id from post id
		if ($post_id)
		{
			$sql = 'SELECT t.topic_id, t.forum_id
				FROM ' . TOPICS_TABLE . ' t, ' . POSTS_TABLE . ' p
				WHERE p.post_id = ' . $post_id . '
				AND t.topic_id = p.topic_id';

			$result      = $this->db->sql_query($sql);
			$topic_forum = $this->db->sql_fetchrow($result);
			$topic_id    = (int) $topic_forum['topic_id'];
			$forum_id    = (int) $topic_forum['forum_id'];
			$this->db->sql_freeresult($result);
			// Todo: What if, bad post id?
		}

		// Find forum id from topic
		else if ($topic_id)
		{
			$sql = 'SELECT forum_id
				FROM ' . TOPICS_TABLE . "
				WHERE topic_id = $topic_id";

			$result   = $this->db->sql_query($sql);
			$forum_id = (int) $this->db->sql_fetchfield('forum_id');
			$this->db->sql_freeresult($result);
			// Todo: What if, bad topic id?
		}


		/*
		 * Now we can check permissions
		 */

		// Can we even view the forum?
		if (!$this->auth->acl_get('f_read', $forum_id))
		{
			$this->json->send(['error' => true]);
			return;
		}

		// Permission checking for different modes
		switch ($mode)
		{
			// Post new topic
			case 'post':
				if (!$this->auth->acl_get('f_post', $forum_id))
				{
					$this->json->send(['error' => true]);
					return;
				}
			break;

			// Reply or quote to topic
			case 'quote':
			case 'reply':
				if (!$this->auth->acl_get('f_reply', $forum_id))
				{
					$this->json->send(['error' => true]);
					return;
				}
			break;

			default:
				$this->json->send(['error' => 'Missing or unhandled mode']);
				return;
		}

		// Todo: permissions event?
		// Todo: Check for locked thread
		// Todo: Check for draft flood limit

		/*
		 * Now we can deal with the message
		 */

		$subject = $this->request->variable('subject', '', true);
		$message = $this->request->variable('message', '', true);

		// Todo: Get subject from topic title
		// $subject = (!$subject && $mode != 'post') ? $post_data['topic_title'] : $subject;

		$subject = utf8_encode_ucr($subject);

		// Todo: Minimum length checks

		if ($subject && $message)
		{
			/*
			 * You know what? This is all getting saved as a draft. I'm skipping all bbcode checks
			 * unless someone says otherwise
			 */
			// Todo: get post settings from config and from post editor
			$allow_bbcode       = true;
			$allow_urls         = true;
			$allow_smilies      = true;
			$allow_img_bbcode   = true;
			$allow_flash_bbcode = true;
			$allow_quote_bbcode = true;
			$allow_url_bbcode   = true;
			// Todo: BBCode events

			// Not used but generate_text_for_storage wants them
			$uid = $bitfield = $flags = '';

			generate_text_for_storage(
				$message,
				$uid,
				$bitfield,
				$flags,
				$allow_bbcode,
				$allow_urls,
				$allow_smilies,
				$allow_img_bbcode,
				$allow_flash_bbcode,
				$allow_quote_bbcode,
				$allow_url_bbcode,
				$mode
			);

			$save_time = time();
			$save_date = $this->user->format_date($save_time);

			$sql_data = [
				'user_id'       => $this->user->id(),
				'topic_id'      => $topic_id,
				'forum_id'      => $forum_id,
				'save_time'     => $save_time,
				'draft_subject' => $subject,
				'draft_message' => $message,
			];

			// Does draft exist?
			$sql      = 'SELECT draft_id FROM ' . DRAFTS_TABLE . ' WHERE draft_id = ' . $draft_id . ' AND  user_id = ' . $this->user->id();
			$result   = $this->db->sql_query($sql);
			$draft_id = $this->db->sql_fetchfield('draft_id', 0, $result);

			if ($draft_id === false)
			{
				$sql = 'INSERT INTO ' . DRAFTS_TABLE . ' ' . $this->db->sql_build_array('INSERT', $sql_data);
				$this->db->sql_query($sql);
				$draft_id = $this->db->sql_nextid();
			}
			else
			{
				$sql = 'UPDATE ' . DRAFTS_TABLE . ' SET ' . $this->db->sql_build_array('UPDATE', $sql_data) . ' 
				WHERE draft_id = ' . $draft_id . ' AND  user_id = ' . $this->user->id();
				$this->db->sql_query($sql);
			}
			$this->json->send([
				'draft_id' => $draft_id,
				'time'     => $save_time,
				'date'     => $save_date,
			]);
			return;
		}
		$this->json->send(['error' => true]);
	}

	public function delete()
	{
		$draft_id = $this->request->variable('d', 0);
		$forum_id = $this->request->variable('f', 0);
		$topic_id = $this->request->variable('t', 0);
		$mode     = $this->request->variable('mode', '');

		// Do nothing logic
		if ($draft_id === 0 || !$this->user->data['is_registered'])
		{
			return;
		}

		// Did we cancel the deletion?
		$cancel = $this->request->variable('cancel', '');
		if ($cancel)
		{
			global $phpbb_root_path, $phpEx;
			if ($topic_id !== 0)
			{
				$url = append_sid($phpbb_root_path . 'posting.' . $phpEx, 'd=' . $draft_id . '&t=' . $topic_id . '&mode=reply', false);
			}
			else
			{
				$url = append_sid($phpbb_root_path . 'posting.' . $phpEx, 'd=' . $draft_id . '&f=' . $forum_id . '&mode=post', false);
			}
			redirect($url);
		}

		$fields = build_hidden_fields([
			'd'    => $draft_id,
			'f'    => $forum_id,
			't'    => $topic_id,
			'mode' => $mode,
		]);

		// Popup confirm box
		if (confirm_box(true))
		{
			$sql = 'DELETE FROM ' . DRAFTS_TABLE . '
				WHERE draft_id = ' . $draft_id . ' AND user_id = ' . $this->user->id();
			$this->db->sql_query($sql);

			if (!$this->request->is_ajax())
			{
				// Todo: we can move these to $this
				global $phpbb_root_path, $phpEx;
				if ($topic_id !== 0)
				{
					$url = append_sid($phpbb_root_path . 'posting.' . $phpEx, 't=' . $topic_id . '&mode=reply', false);
				}
				else
				{
					$url = append_sid($phpbb_root_path . 'posting.' . $phpEx, 'f=' . $forum_id . '&mode=post', false);
				}
				meta_refresh(3, $url);
				trigger_error('Draft deleted');
			}
			$this->json->send([
				'status'   => 'success',
				'draft_id' => $draft_id,
				'action'   => 'delete',
			]);
		}
		else
		{
			confirm_box(false, $this->language->lang('CONFIRM_OPERATION'), $fields, 'confirm_body.html', $this->helper->route('crosstimecafe_dbautodraft_delete'));
		}
	}
}
