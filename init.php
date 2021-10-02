<?php
class OpenCC extends Plugin
{

    /* @var PluginHost $host */
    private $host;

    public function about()
    {
        return array(1.0,
            "Conversion between Traditional and Simplified Chinese via OpenCC",
            "HenryQW");
    }

    public function flags()
    {
        return array("needs_curl" => true);
    }

    public function save()
    {
        $this->host->set($this, "opencc_API_server", $_POST["opencc_API_server"]);

        echo __("API server address saved.");
    }

    public function init($host)
    {
        $this->host = $host;

        if (version_compare(PHP_VERSION, '7.0.0', '<')) {
            user_error("opencc requires PHP 7.0", E_USER_WARNING);
            return;
        }

        $host->add_hook($host::HOOK_ARTICLE_FILTER, $this);
        $host->add_hook($host::HOOK_PREFS_TAB, $this);
        $host->add_hook($host::HOOK_PREFS_EDIT_FEED, $this);
        $host->add_hook($host::HOOK_PREFS_SAVE_FEED, $this);
        $host->add_hook($host::HOOK_ARTICLE_BUTTON, $this);

        $host->add_filter_action($this, "OpenCC", __("OpenCC"));
    }

    public function get_js()
    {
        return file_get_contents(__DIR__ . "/init.js");
    }

    public function hook_article_button($line)
    {
        return "<i class='material-icons'
			style='cursor : pointer' onclick='Plugins.OpenCC.convert(".$line["id"].")'
			title='".__('Convert via OpenCC')."'>g_translate</i>";
    }


    public function hook_prefs_tab($args)
    {
        if ($args != "prefFeeds") {
            return;
        }

        print "<div dojoType='dijit.layout.AccordionPane' 
			title=\"<i class='material-icons'>extension</i> ".__('opencc settings (OpenCC)')."\">";

        if (version_compare(PHP_VERSION, '7.0.0', '<')) {
            print_error("This plugin requires PHP 7.0.");
        } else {
            print_notice("Enable the plugin for specific feeds in the feed editor.");

            print "<form dojoType='dijit.form.Form'>";

            print "<script type='dojo/method' event='onSubmit' args='evt'>
                evt.preventDefault();
                if (this.validate()) {
                xhr.post(\"backend.php\", this.getValues(), (reply) => {
                            Notify.info(reply);
                        })
                }
                </script>";

            print \Controls\pluginhandler_tags($this, "save");

            $opencc_API_server = $this->host->get($this, "opencc_API_server");

            print "<input dojoType='dijit.form.ValidationTextBox' required='1' name='opencc_API_server' value='" . $opencc_API_server . "'/>";

            print "&nbsp;<label for='opencc_API_server'>" . __("OpenCC API server address, with HTTP/HTTPS protocol.") . "</label>";

            print "<p>Read the <a href='http://ttrss.henry.wang/#opencc-simp-trad-chinese-conversion'>documents</a>.</p>";
            print "<button dojoType=\"dijit.form.Button\" type=\"submit\" class=\"alt-primary\">".__('Save')."</button>";

            print "</form>";

            $enabled_feeds = $this->host->get($this, "enabled_feeds");
            if (!is_array($enabled_feeds)) {
                $enabled_feeds = array();
            }

            $enabled_feeds = $this->filter_unknown_feeds($enabled_feeds);
            $this->host->set($this, "enabled_feeds", $enabled_feeds);

            if (count($enabled_feeds) > 0) {
                print "<h3>" . __("Currently enabled for (click to edit):") . "</h3>";

                print "<ul class='panel panel-scrollable list list-unstyled'>";
                foreach ($enabled_feeds as $f) {
                    print "<li>" .
                    "<i class='material-icons'>rss_feed</i> <a href='#'
						onclick='CommonDialogs.editFeed($f)'>".
                    Feeds::_get_title($f) . "</a></li>";

                }
                print "</ul>";
            }
        }

        print "</div>";
    }

    public function hook_prefs_edit_feed($feed_id)
    {
        print "<header>".__("OpenCC")."</header>";
        print "<section>";

        $enabled_feeds = $this->host->get($this, "enabled_feeds");
        if (!is_array($enabled_feeds)) {
            $enabled_feeds = array();
        }

        $key = array_search($feed_id, $enabled_feeds);
        $checked = $key !== false ? "checked" : "";

        print "<fieldset>";

        print "<label class='checkbox'><input dojoType='dijit.form.CheckBox' type='checkbox' id='opencc_enabled'
				name='opencc_enabled' $checked>&nbsp;".__('Enable OpenCC')."</label>";
    
        print "</fieldset>";
    
        print "</section>";
    }

    public function hook_prefs_save_feed($feed_id)
    {
        $enabled_feeds = $this->host->get($this, "enabled_feeds");
        if (!is_array($enabled_feeds)) {
            $enabled_feeds = array();
        }

        $enable = checkbox_to_sql_bool($_POST["opencc_enabled"]);
        $key = array_search($feed_id, $enabled_feeds);

        if ($enable) {
            if ($key === false) {
                array_push($enabled_feeds, $feed_id);
            }
        } else {
            if ($key !== false) {
                unset($enabled_feeds[$key]);
            }
        }

        $this->host->set($this, "enabled_feeds", $enabled_feeds);
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function hook_article_filter_action($article, $action)
    {
        return $this->process_article($article);
    }
    
    
    public function send_request($title, $content)
    {
        $ch = curl_init();
        $opencc_API_server = $this->host->get($this, "opencc_API_server");
        $request_headers = array();
        $request_body = array(
                'title' => urlencode($title),
                'content' => urlencode($content)
            );

        $request_body_string = "";

        foreach ($request_body as $key=>$value) {
            $request_body_string .= $key.'='.$value.'&';
        }
        rtrim($request_body_string, '&');
    
        curl_setopt($ch, CURLOPT_URL, $opencc_API_server);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $request_headers);
        curl_setopt($ch, CURLOPT_ENCODING, "UTF-8");
        curl_setopt($ch, CURLOPT_POST, count($request_body));
        curl_setopt($ch, CURLOPT_POSTFIELDS, $request_body_string);
    
        $output = json_decode(curl_exec($ch));
        curl_close($ch);

        return $output;
    }
    
    public function process_article($article)
    {
        $output = $this->send_request($article["title"], $article["content"]);

        if ($output->title) {
            $article["title"] = $output->title;
        }

        if ($output->content) {
            $article["content"] = $output->content;
        }

        return $article;
    }

    public function hook_article_filter($article)
    {
        $enabled_feeds = $this->host->get($this, "enabled_feeds");
        if (!is_array($enabled_feeds)) {
            return $article;
        }

        $key = array_search($article["feed"]["id"], $enabled_feeds);
        if ($key === false) {
            return $article;
        }

        return $this->process_article($article);
    }

    public function api_version()
    {
        return 2;
    }

    private function filter_unknown_feeds($enabled_feeds)
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

    public function convert()
    {
        $article_id = (int) $_REQUEST["id"];

        $sth = $this->pdo->prepare("SELECT title, content FROM ttrss_entries WHERE id = ?");
        $sth->execute([$article_id]);

        if ($row = $sth->fetch()) {
            $output = $this->send_request($row["title"], $row["content"]);
        }

        $result=[];
        
        if ($output->title) {
            $result["title"] = $output->title;
        }

        if ($output->content) {
            $result["content"] = $output->content;
        }

        print json_encode($result);
    }
}
