<?php
class Af_Psql_Trgm extends Plugin {

	private $host;

	function about() {
		return array(1.0,
			"Marks similar articles as read (requires pg_trgm)",
			"fox");
	}

	function save() {
		$similarity = (float) db_escape_string($_POST["similarity"]);
		$min_title_length = (int) db_escape_string($_POST["min_title_length"]);
		$enable_globally = checkbox_to_sql_bool($_POST["enable_globally"]) == "true";

		if ($similarity < 0) $similarity = 0;
		if ($similarity > 1) $similarity = 1;

		if ($min_title_length < 0) $min_title_length = 0;

		$similarity = sprintf("%.2f", $similarity);

		$this->host->set($this, "similarity", $similarity);
		$this->host->set($this, "min_title_length", $min_title_length);
		$this->host->set($this, "enable_globally", $enable_globally);

		echo T_sprintf("Data saved (%s, %d)", $similarity, $enable_globally);
	}

	function init($host) {
		$this->host = $host;

		$host->add_hook($host::HOOK_ARTICLE_FILTER, $this);
		$host->add_hook($host::HOOK_PREFS_TAB, $this);
		$host->add_hook($host::HOOK_PREFS_EDIT_FEED, $this);
		$host->add_hook($host::HOOK_PREFS_SAVE_FEED, $this);
		$host->add_hook($host::HOOK_ARTICLE_BUTTON, $this);

	}

	function get_js() {
		return file_get_contents(__DIR__ . "/init.js");
	}

	function showrelated() {
		$id = (int) db_escape_string($_REQUEST['param']);
		$owner_uid = $_SESSION["uid"];

		$result = db_query("SELECT title FROM ttrss_entries, ttrss_user_entries
			WHERE ref_id = id AND id = $id AND owner_uid = $owner_uid");

		$title = db_fetch_result($result, 0, "title");

		print "<h2>$title</h2>";

		$title = db_escape_string($title);
		$result = db_query("SELECT ttrss_entries.id AS id,
				feed_id,
				ttrss_entries.title AS title,
				updated, link,
				ttrss_feeds.title AS feed_title,
				SIMILARITY(ttrss_entries.title, '$title') AS sm
			FROM
				ttrss_entries, ttrss_user_entries LEFT JOIN ttrss_feeds ON (ttrss_feeds.id = feed_id)
			WHERE
				ttrss_entries.id = ref_id AND
				ttrss_user_entries.owner_uid = $owner_uid AND
				ttrss_entries.id != $id AND
				date_entered >= NOW() - INTERVAL '2 weeks'
			ORDER BY
				sm DESC, date_entered DESC
			LIMIT 10");

		print "<ul class=\"browseFeedList\" style=\"border-width : 1px\">";

		while ($line = db_fetch_assoc($result)) {
			print "<li>";
			print "<div class='insensitive small' style='margin-left : 20px; float : right'>" .
				smart_date_time(strtotime($line["updated"]))
				. "</div>";

			$sm = sprintf("%.2f", $line['sm']);
			print "<img src='images/score_high.png' title='$sm'
				style='vertical-align : middle'>";

			$article_link = htmlspecialchars($line["link"]);
			print " <a target=\"_blank\" rel=\"noopener noreferrer\" href=\"$article_link\">".
				$line["title"]."</a>";

			print " (<a href=\"#\" onclick=\"viewfeed({feed:".$line["feed_id"]."})\">".
				htmlspecialchars($line["feed_title"])."</a>)";

			print " <span class='insensitive'>($sm)</span>";

			print "</li>";
		}

		print "</ul>";

		print "<div style='text-align : center'>";
		print "<button dojoType=\"dijit.form.Button\" onclick=\"dijit.byId('trgmRelatedDlg').hide()\">".__('Close this window')."</button>";
		print "</div>";


	}

	function hook_article_button($line) {
		return "<img src=\"plugins/af_psql_trgm/button.png\"
			style=\"cursor : pointer\" style=\"cursor : pointer\"
			onclick=\"showTrgmRelated(".$line["id"].")\"
			class='tagsPic' title='".__('Show related articles')."'>";
	}

	function hook_prefs_tab($args) {
		if ($args != "prefFeeds") return;

		print "<div dojoType=\"dijit.layout.AccordionPane\" title=\"".__('Mark similar articles as read')."\">";

		if (DB_TYPE != "pgsql") {
			print_error("Database type not supported.");
		} else {

			$result = db_query("select 'similarity'::regproc");

			if (db_num_rows($result) == 0) {
				print_error("pg_trgm extension not found.");
			}

			$similarity = $this->host->get($this, "similarity");
			$min_title_length = $this->host->get($this, "min_title_length");
			$enable_globally = $this->host->get($this, "enable_globally");

			if (!$similarity) $similarity = '0.75';
			if (!$min_title_length) $min_title_length = '32';

			print "<form dojoType=\"dijit.form.Form\">";

			print "<script type=\"dojo/method\" event=\"onSubmit\" args=\"evt\">
				evt.preventDefault();
				if (this.validate()) {
					console.log(dojo.objectToQuery(this.getValues()));
					new Ajax.Request('backend.php', {
						parameters: dojo.objectToQuery(this.getValues()),
						onComplete: function(transport) {
							notify_info(transport.responseText);
						}
					});
					//this.reset();
				}
				</script>";

			print_hidden("op", "pluginhandler");
			print_hidden("method", "save");
			print_hidden("plugin", "af_psql_trgm");

			print "<p>" . __("PostgreSQL trigram extension returns string similarity as a floating point number (0-1). Setting it too low might produce false positives, zero disables checking.") . "</p>";
			print_notice("Enable the plugin for specific feeds in the feed editor.");

			print "<h3>" . __("Global settings") . "</h3>";

			print "<table>";

			print "<tr><td width=\"40%\">" . __("Minimum similarity:") . "</td>";
			print "<td>
				<input dojoType=\"dijit.form.ValidationTextBox\"
				placeholder=\"0.75\"
				required=\"1\" name=\"similarity\" value=\"$similarity\"></td></tr>";
			print "<tr><td width=\"40%\">" . __("Minimum title length:") . "</td>";
			print "<td>
				<input dojoType=\"dijit.form.ValidationTextBox\"
				placeholder=\"32\"
				required=\"1\" name=\"min_title_length\" value=\"$min_title_length\"></td></tr>";
			print "<tr><td width=\"40%\">" . __("Enable for all feeds:") . "</td>";
			print "<td>";
			print_checkbox("enable_globally", $enable_globally);
			print "</td></tr>";

			print "</table>";

			print "<p>"; print_button("submit", __("Save"));
			print "</form>";

			$enabled_feeds = $this->host->get($this, "enabled_feeds");
			if (!array($enabled_feeds)) $enabled_feeds = array();

			$enabled_feeds = $this->filter_unknown_feeds($enabled_feeds);
			$this->host->set($this, "enabled_feeds", $enabled_feeds);

			if (count($enabled_feeds) > 0) {
				print "<h3>" . __("Currently enabled for (click to edit):") . "</h3>";

				print "<ul class=\"browseFeedList\" style=\"border-width : 1px\">";
				foreach ($enabled_feeds as $f) {
					print "<li>" .
						"<img src='images/pub_set.png'
							style='vertical-align : middle'> <a href='#'
							onclick='editFeed($f)'>" .
						getFeedTitle($f) . "</a></li>";
				}
				print "</ul>";
			}
		}

		print "</div>";
	}

	function hook_prefs_edit_feed($feed_id) {
		print "<div class=\"dlgSec\">".__("Similarity (pg_trgm)")."</div>";
		print "<div class=\"dlgSecCont\">";

		$enabled_feeds = $this->host->get($this, "enabled_feeds");
		if (!array($enabled_feeds)) $enabled_feeds = array();

		$key = array_search($feed_id, $enabled_feeds);
		$checked = $key !== FALSE ? "checked" : "";

		print "<hr/><input dojoType=\"dijit.form.CheckBox\" type=\"checkbox\" id=\"trgm_similarity_enabled\"
			name=\"trgm_similarity_enabled\"
			$checked>&nbsp;<label for=\"trgm_similarity_enabled\">".__('Mark similar articles as read')."</label>";

		print "</div>";
	}

	function hook_prefs_save_feed($feed_id) {
		$enabled_feeds = $this->host->get($this, "enabled_feeds");
		if (!is_array($enabled_feeds)) $enabled_feeds = array();

		$enable = checkbox_to_sql_bool($_POST["trgm_similarity_enabled"]) == 'true';
		$key = array_search($feed_id, $enabled_feeds);

		if ($enable) {
			if ($key === FALSE) {
				array_push($enabled_feeds, $feed_id);
			}
		} else {
			if ($key !== FALSE) {
				unset($enabled_feeds[$key]);
			}
		}

		$this->host->set($this, "enabled_feeds", $enabled_feeds);
	}

	function hook_article_filter($article) {

		if (DB_TYPE != "pgsql") return $article;

		$result = db_query("select 'similarity'::regproc");
		if (db_num_rows($result) == 0) return $article;

		$enable_globally = $this->host->get($this, "enable_globally");

		if (!$enable_globally) {
			$enabled_feeds = $this->host->get($this, "enabled_feeds");
			$key = array_search($article["feed"]["id"], $enabled_feeds);
			if ($key === FALSE) return $article;
		}

		$similarity = (float) $this->host->get($this, "similarity");
		if ($similarity < 0.01) return $article;

		$min_title_length = (int) $this->host->get($this, "min_title_length");
		if (mb_strlen($article["title"]) < $min_title_length) return $article;

		$owner_uid = $article["owner_uid"];
		$entry_guid = $article["guid_hashed"];
		$title_escaped = db_escape_string($article["title"]);

		// trgm does not return similarity=1 for completely equal strings

		$result = db_query("SELECT COUNT(id) AS nequal
		  FROM ttrss_entries, ttrss_user_entries WHERE ref_id = id AND
		  date_entered >= NOW() - interval '3 days' AND
		  title = '$title_escaped' AND
		  guid != '$entry_guid' AND
		  owner_uid = $owner_uid");

		$nequal = db_fetch_result($result, 0, "nequal");
		_debug("af_psql_trgm: num equals: $nequal");

		if ($nequal != 0) {
			$article["force_catchup"] = true;
			return $article;
		}

		$result = db_query("SELECT MAX(SIMILARITY(title, '$title_escaped')) AS ms
		  FROM ttrss_entries, ttrss_user_entries WHERE ref_id = id AND
		  date_entered >= NOW() - interval '1 day' AND
		  guid != '$entry_guid' AND
		  owner_uid = $owner_uid");

		$similarity_result = db_fetch_result($result, 0, "ms");

		_debug("af_psql_trgm: similarity result: $similarity_result");

		if ($similarity_result >= $similarity) {
			$article["force_catchup"] = true;
		}

		return $article;

	}

	function api_version() {
		return 2;
	}

	private function filter_unknown_feeds($enabled_feeds) {
		$tmp = array();

		foreach ($enabled_feeds as $feed) {

			$result = db_query("SELECT id FROM ttrss_feeds WHERE id = '$feed' AND owner_uid = " . $_SESSION["uid"]);

			if (db_num_rows($result) != 0) {
				array_push($tmp, $feed);
			}
		}

		return $tmp;
	}

}
?>
