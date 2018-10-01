<?php
class ttrss_opencc extends Plugin

	{
	private $host;
	function about()
		{
		return array(
			1.0,
			"Conversion between Traditional and Simplified Chinese via OpenCC",
			"https://github.com/HenryQW/opencc/"
		);
		}

	function flags()
		{
		return array(
			"needs_curl" => true
		);
		}

	function save()
		{
		$this->host->set($this, "mercury_API", $_POST["mercury_API"]);
		echo __("API key saved.");
		}

	function init($host)
		{
		$this->host = $host;
		$host->add_hook($host::HOOK_ARTICLE_FILTER, $this);
		$host->add_hook($host::HOOK_PREFS_TAB, $this);
		$host->add_hook($host::HOOK_PREFS_EDIT_FEED, $this);
		$host->add_hook($host::HOOK_PREFS_SAVE_FEED, $this);
		$host->add_filter_action($this, "action_inline", __("Inline content"));
		}

	function hook_prefs_tab($args)
		{
		if ($args != "prefFeeds") return;
		print "<div dojoType=\"dijit.layout.AccordionPane\" title=\"" . __('opencc settings (opencc)') . "\">";
		print_notice("Enable the plugin for specific feeds in the feed editor.");
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

				// this.reset();

			}
			</script>";
		print_hidden("op", "pluginhandler");
		print_hidden("method", "save");
		print_hidden("plugin", "opencc");
		$mercury_API = $this->host->get($this, "mercury_API");
		print "<input dojoType='dijit.form.ValidationTextBox' required='1' name='mercury_API' value='" . $mercury_API . "'/>";
		print "&nbsp;<label for=\"mercury_API\">" . __("Get your own API key here: https://mercury.postlight.com/web-parser/") . "</label>";
		print "<p>";
		print_button("submit", __("Save"));
		print "</form>";
		$enabled_feeds = $this->host->get($this, "enabled_feeds");
		if (!is_array($enabled_feeds)) $enabled_feeds = array();
		$enabled_feeds = $this->filter_unknown_feeds($enabled_feeds);
		$this->host->set($this, "enabled_feeds", $enabled_feeds);
		if (count($enabled_feeds) > 0)
			{
			print "<h3>" . __("Currently enabled for (click to edit):") . "</h3>";
			print "<ul class=\"browseFeedList\" style=\"border-width : 1px\">";
			foreach($enabled_feeds as $f)
				{
				print "<li>" . "<img src='images/pub_set.png'
						style='vertical-align : middle'> <a href='#'
						onclick='editFeed($f)'>" . Feeds::getFeedTitle($f) . "</a></li>";
				}

			print "</ul>";
			}

		print "</div>";
		}

	function hook_prefs_edit_feed($feed_id)
		{
		print "<div class=\"dlgSec\">" . __("Mercury") . "</div>";
		print "<div class=\"dlgSecCont\">";
		$enabled_feeds = $this->host->get($this, "enabled_feeds");
		if (!is_array($enabled_feeds)) $enabled_feeds = array();
		$key = array_search($feed_id, $enabled_feeds);
		$checked = $key !== FALSE ? "checked" : "";
		print "<hr/><input dojoType=\"dijit.form.CheckBox\" type=\"checkbox\" id=\"opencc_enabled\"
			name=\"opencc_enabled\"
			$checked>&nbsp;<label for=\"opencc_enabled\">" . __('Conversion between Traditional and Simplified Chinese via OpenCC') . "</label>";
		print "</div>";
		}

	function hook_prefs_save_feed($feed_id)
		{
		$enabled_feeds = $this->host->get($this, "enabled_feeds");
		if (!is_array($enabled_feeds)) $enabled_feeds = array();
		$enable = checkbox_to_sql_bool($_POST["opencc_enabled"]);
		$key = array_search($feed_id, $enabled_feeds);
		if ($enable)
			{
			if ($key === FALSE)
				{
				array_push($enabled_feeds, $feed_id);
				}
			}
		  else
			{
			if ($key !== FALSE)
				{
				unset($enabled_feeds[$key]);
				}
			}

		$this->host->set($this, "enabled_feeds", $enabled_feeds);
		}

	/**
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	function hook_article_filter_action($article, $action)
		{
		return $this->process_article($article);
		}

	function process_article($article)
		{
			$ch = curl_init();
			$url = $article['link'];
			$api_key = $this->host->get($this, "opencc_API");
			$request_headers = array();
			$request_body = array(
				'content' => urlencode($article['content']),
				'title' => urlencode($article['title'])
			);
	
			foreach($request_body as $key=>$value) { $request_body_string .= $key.'='.$value.'&'; }
	rtrim($request_body_string, '&');
	
			curl_setopt($ch, CURLOPT_URL, 'https://rss.serveo.net/api/v1/opencc?apikey=' .$api_key);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_HTTPHEADER, $request_headers);
			curl_setopt($ch, CURLOPT_ENCODING, "UTF-8");
			curl_setopt($ch, CURLOPT_POST, count($request_body));
			curl_setopt($ch, CURLOPT_POSTFIELDS, $request_body_string);
	
			$output = json_decode(curl_exec($ch));
			curl_close($ch);
			$extracted_title = $output->title;
			$extracted_content = $output->content;

			if ($extracted_title)
			{
				$article["title"] = $extracted_title;
			}

			if ($extracted_content)
			{
				$article["content"] = $extracted_content;
			}

		return $article;
		}


	function hook_article_filter($article)
		{
		$enabled_feeds = $this->host->get($this, "enabled_feeds");
		if (!is_array($enabled_feeds)) return $article;
		$key = array_search($article["feed"]["id"], $enabled_feeds);
		if ($key === FALSE) return $article;
		return $this->process_article($article);
		}

	function api_version()
		{
		return 2;
		}

	private
	function filter_unknown_feeds($enabled_feeds)
		{
		$tmp = array();
		foreach ($enabled_feeds as $feed) {
			$sth = $this->pdo->prepare("SELECT id FROM ttrss_feeds WHERE id = ? AND owner_uid = ?");
			$sth->execute([$feed, $_SESSION['uid']]);

			if ($row = $sth->fetch()) {
				array_push($tmp, $feed);
			}
		}

		return $tmp;
		}
	}