<?php
/**
 *
 * DB Auto Draft. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2022, Neo, https://crosstimecafe.com
 * @license       GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace crosstimecafe\dbautodraft\event;

/**
 * @ignore
 */

use phpbb\controller\helper;
use phpbb\db\driver\driver_interface;
use phpbb\language\language;
use phpbb\request\request;
use phpbb\template\template;
use phpbb\user;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * DB Auto Draft Event listener.
 */
class main_listener implements EventSubscriberInterface
{
	public static function getSubscribedEvents()
	{
		return [
			'core.posting_modify_template_vars' => 'populate_draft_panel',
		];
	}

	protected $language;
	protected $helper;
	protected $template;
	protected $php_ext;
	private $db;
	private $user;
	private $request;

	/**
	 * Constructor
	 *
	 * @param \phpbb\language\language $language Language object
	 * @param \phpbb\controller\helper $helper   Controller helper object
	 * @param \phpbb\template\template $template Template object
	 * @param string                   $php_ext  phpEx
	 */
	public function __construct(language $language, helper $helper, template $template, $php_ext, user $user, driver_interface $db, request $request)
	{
		$this->language = $language;
		$this->helper   = $helper;
		$this->template = $template;
		$this->php_ext  = $php_ext;
		$this->user     = $user;
		$this->db       = $db;
		$this->request  = $request;
	}

	public function populate_draft_panel($event)
	{
		// If the post is being saved we can skip everything.
		// phpBB will handle deleting the draft
		if ($event['submit'])
		{
			return;
		}

		// Skip non-users should guest posting be enabled
		if (!$this->user->data['is_registered'])
		{
			return;
		}

		// Load variables so we are not polling the event variable
		$forum_id = $event['forum_id'];
		$topic_id = $event['topic_id'];
		$mode     = $event['mode'];

		$draft_id = $event['draft_id'];
		// Try getting id from hidden field
		if ($draft_id === 0)
		{
			$draft_id = $this->request->variable('draft_loaded', 0);
		}

		if ($topic_id)
		{
			// Fetch reply drafts
			$this->template->assign_var('DRAFT_TYPE', 'Reply drafts');
			$sql = 'FROM ' . DRAFTS_TABLE . '
				WHERE user_id = ' . $this->user->id() . ' AND topic_id = ' . $topic_id . ' ORDER BY save_time DESC';
		}

		else
		{
			// Fetch new post drafts
			$this->template->assign_var('DRAFT_TYPE', 'New topic drafts');
			$sql = 'FROM ' . DRAFTS_TABLE . '
				WHERE user_id = ' . $this->user->id() . ' AND topic_id = 0 AND forum_id = ' . $forum_id . ' ORDER BY save_time DESC';
		}
		$result = $this->db->sql_query('SELECT * ' . $sql);

		// Quickly fetch draft count
		$result_count = $this->db->sql_query('SELECT COUNT(draft_id) AS count ' . $sql);
		$count        = $this->db->sql_fetchfield('count', 0, $result_count);
		$this->template->assign_var('TOTAL_DRAFTS', $count);
		$this->db->sql_freeresult($result_count);

		while ($row = $this->db->sql_fetchrow($result))
		{
			$this->template->assign_block_vars('autodraft_row', [
				'SUBJECT'      => $row['draft_subject'],
				'DATE'         => $this->user->format_date($row['save_time']),
				'ID'           => $row['draft_id'],
				'LOADED_DRAFT' => $draft_id == $row['draft_id'],
				'URL_VIEW'     => $this->helper->route('crosstimecafe_dbautodraft_load', [
					'd'    => $row['draft_id'],
					't'    => $topic_id,
					'f'    => $forum_id,
					'mode' => $mode,
				], false),
				'URL_DELETE'   => $this->helper->route('crosstimecafe_dbautodraft_delete', [
					'd'    => $row['draft_id'],
					't'    => $topic_id,
					'f'    => $forum_id,
					'mode' => $mode,
				], false),
			]);
		}
		$this->db->sql_freeresult($result);
		// Todo: Deal with deleting currently loaded draft. What happens to draft update if the draft goes way?

		// Create URLs to pass to JavaScript
		$this->template->assign_var('SAVE_URL', $this->helper->route('crosstimecafe_dbautodraft_save'));
		$this->template->assign_var('VIEW_URL', $this->helper->route('crosstimecafe_dbautodraft_load', [
			'd'    => '0',
			't'    => $topic_id,
			'f'    => $forum_id,
			'mode' => $mode,
		], false, false, 0));
		$this->template->assign_var('DELETE_URL', $this->helper->route('crosstimecafe_dbautodraft_delete', [
			'd'    => '0',
			't'    => $topic_id,
			'f'    => $forum_id,
			'mode' => $mode,
		], false, false, 0));
	}
}
