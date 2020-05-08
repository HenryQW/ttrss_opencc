Plugins.OpenCC = {
  orig_content: "data-opencc-orig-content",
  self: this,
  convert: function (id) {
    const content = $$(
      App.isCombinedMode()
        ? ".cdm[data-article-id=" + id + "] .content-inner"
        : ".post[data-article-id=" + id + "] .content"
    )[0];

    const title = $$(
      App.isCombinedMode()
        ? ".cdm[data-article-id=" + id + "] .title"
        : ".post[data-article-id=" + id + "] .title > a"
    )[0];

    if (content.hasAttribute(self.orig_content)) {
      content.innerHTML = content.getAttribute(self.orig_content);
      content.removeAttribute(self.orig_content);

      if (title.hasAttribute("title")) {
        title.text = title.getAttribute("title");
      }

      if (App.isCombinedMode()) Article.cdmScrollToId(id);

      return;
    }

    Notify.progress("Loading, please wait...");

    xhrJson(
      "backend.php",
      { op: "pluginhandler", plugin: "opencc", method: "convert", param: id },
      (reply) => {
        if (content && reply.content) {
          content.setAttribute(self.orig_content, content.innerHTML);
          content.innerHTML = reply.content;
          if (reply.title) {
            title.text = reply.title;
          }
          Notify.close();
          if (App.isCombinedMode()) Article.cdmScrollToId(id);
        } else {
          Notify.error("Unable to convert via OpenCC for this article");
        }
      }
    );
  },
};
