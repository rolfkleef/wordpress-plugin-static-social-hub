/**
 * Static Social Hub — embeddable JS widget
 *
 * Auto-embed usage on your static site:
 *
 *   <link rel="stylesheet" href="https://[wp-host]/wp-content/plugins/static-social-hub/assets/static-social-hub.css">
 *   <div id="ssh-comments"></div>
 *   <script src="https://[wp-host]/wp-content/plugins/static-social-hub/assets/static-social-hub.js"
 *           data-api="https://[wp-host]/wp-json/static-social-hub/v1"></script>
 *
 * Programmatic usage (e.g. admin preview):
 *
 *   window.SSH.mount(containerElement, pageUrl, apiBase, theme);
 *
 * Widget reads (auto-embed mode):
 *   data-api   (script tag)    — REST API base URL, required for auto-embed.
 *   data-url   (#ssh-comments) — static page URL; defaults to window.location.href.
 *   data-theme (#ssh-comments) — "light", "dark", or "auto" (default).
 *
 * @package StaticSocialHub
 * @license AGPL-3.0-or-later
 */
(function () {
  'use strict';

  // ---------------------------------------------------------------------------
  // Public API
  // ---------------------------------------------------------------------------

  window.SSH = window.SSH || {};

  /**
   * Mounts the widget into `containerEl`.
   *
   * @param {HTMLElement} containerEl  Target element to render into.
   * @param {string}      pageUrl      Static page URL to fetch reactions for.
   * @param {string}      api          REST API base URL (no trailing slash).
   * @param {string}      [theme]      "light", "dark", or "auto" (default).
   * @param {boolean}     [preview]    If true, intercepts comment form (no real submission).
   * @param {Object}      [demoData]   Pre-loaded reactions payload; skips fetchReactions() when supplied.
   *                                   Shape: { likes, boosts, replies, webmentions, comments }
   *                                   The comment form still submits real comments via the API.
   */
  window.SSH.mount = function (containerEl, pageUrl, api, theme, preview, demoData) {
    if (!containerEl || !pageUrl || !api) {
      console.warn('[SDB] mount() requires containerEl, pageUrl, and api.');
      return;
    }
    theme   = theme || 'auto';
    preview = preview || containerEl.getAttribute('data-preview') === 'true';
    api     = api.replace(/\/$/, '');

    containerEl.className = 'ssh-widget ssh-theme-' + theme;

    if (demoData && typeof demoData === 'object') {
      render(containerEl, demoData, pageUrl, api, preview);
      return;
    }

    containerEl.innerHTML = '<div class="ssh-loading" aria-live="polite">' + t('Loading\u2026') + '</div>';

    fetchReactions(pageUrl, api, function (err, data) {
      if (err) {
        containerEl.innerHTML = '<p class="ssh-error">' + esc(t('Could not load comments. Please try again later.')) + '</p>';
        return;
      }
      render(containerEl, data, pageUrl, api, preview);
    });
  };

  // ---------------------------------------------------------------------------
  // Auto-embed initialisation (classic <script> tag)
  // ---------------------------------------------------------------------------

  var scriptTag = document.currentScript ||
    (function () {
      var scripts = document.getElementsByTagName('script');
      return scripts[scripts.length - 1];
    })();

  var autoApiBase = (scriptTag.getAttribute('data-api') || '').replace(/\/$/, '');

  // If no data-api, skip auto-embed; caller will use window.SSH.mount() directly.
  if (autoApiBase) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', autoInit);
    } else {
      autoInit();
    }
  }

  function autoInit() {
    var root = document.getElementById('ssh-comments');
    if (!root) {
      console.warn('[SDB] No element with id="ssh-comments" found.');
      return;
    }
    var pageUrl = root.getAttribute('data-url') || window.location.href;
    var theme   = root.getAttribute('data-theme') || 'auto';
    window.SSH.mount(root, pageUrl, autoApiBase, theme);
  }

  // ---------------------------------------------------------------------------
  // Data fetching
  // ---------------------------------------------------------------------------

  function fetchReactions(pageUrl, api, callback) {
    var url = api + '/reactions?url=' + encodeURIComponent(pageUrl);
    fetch(url, { credentials: 'include' })
      .then(function (res) {
        if (!res.ok) { throw new Error('HTTP ' + res.status); }
        return res.json();
      })
      .then(function (data) { callback(null, data); })
      .catch(function (err) { callback(err); });
  }

  function submitComment(payload, api, callback) {
    fetch(api + '/comments', {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    })
      .then(function (res) {
        return res.json().then(function (body) {
          if (!res.ok) { throw body; }
          return body;
        });
      })
      .then(function (data) { callback(null, data); })
      .catch(function (err) { callback(err); });
  }

  // ---------------------------------------------------------------------------
  // Render
  // ---------------------------------------------------------------------------

  function render(root, data, pageUrl, api, preview) {
    var html = '';

    var totalLikes = (data.likes       || []).length;
    var totalBoosts = (data.boosts     || []).length;
    var totalFed   = (data.replies     || []).length;
    var totalWm    = (data.webmentions || []).length;
    var totalLocal = (data.comments    || []).length;
    var totalAll   = totalFed + totalWm + totalLocal;

    // Reactions bar — always shown when there are reactions or when a WP admin
    // button should appear (detected via readable wp-settings-time cookie).
    var showAdmin = hasWpSettingsCookie() && data.admin;
    if (totalLikes + totalBoosts + totalAll > 0 || showAdmin) {
      html += '<div class="ssh-reactions-bar">';
      if (totalLikes > 0) {
        html += '<button class="ssh-reaction-toggle" aria-expanded="false" data-target="ssh-likes-list-' + root.id + '">';
        html += '\u2764\uFE0F <span class="ssh-count">' + totalLikes + '</span> ' + esc(totalLikes === 1 ? t('like') : t('likes'));
        html += '</button>';
      }
      if (totalBoosts > 0) {
        html += '<button class="ssh-reaction-toggle" aria-expanded="false" data-target="ssh-boosts-list-' + root.id + '">';
        html += '\uD83D\uDD01 <span class="ssh-count">' + totalBoosts + '</span> ' + esc(totalBoosts === 1 ? t('boost') : t('boosts'));
        html += '</button>';
      }
      if (totalAll > 0) {
        html += '<button class="ssh-reaction-toggle" aria-expanded="true" data-target="ssh-replies-list-' + root.id + '">';
        html += '\uD83D\uDCAC <span class="ssh-count">' + totalAll + '</span> ' + esc(totalAll === 1 ? t('reply') : t('replies'));
        html += '</button>';
      }
      if (showAdmin) {
        html += data.post_id ? adminEditLinkHtml(data.admin.edit_url) : adminCreateBtnHtml();
      }
      html += '</div>';
    }

    // Likes panel (collapsible avatar grid)
    if (totalLikes > 0) {
      html += '<div id="ssh-likes-list-' + root.id + '" class="ssh-avatar-list ssh-collapsed" aria-hidden="true">';
      html += '<h3 class="ssh-section-title">' + esc(t('Likes')) + '</h3>';
      html += '<ul class="ssh-avatars">';
      (data.likes || []).forEach(function (item) { html += '<li>' + renderAvatar(item) + '</li>'; });
      html += '</ul></div>';
    }

    // Boosts panel (collapsible avatar grid)
    if (totalBoosts > 0) {
      html += '<div id="ssh-boosts-list-' + root.id + '" class="ssh-avatar-list ssh-collapsed" aria-hidden="true">';
      html += '<h3 class="ssh-section-title">' + esc(t('Boosts')) + '</h3>';
      html += '<ul class="ssh-avatars">';
      (data.boosts || []).forEach(function (item) { html += '<li>' + renderAvatar(item) + '</li>'; });
      html += '</ul></div>';
    }

    // Unified replies panel: Fediverse + webmentions + local comments, sorted chronologically
    if (totalAll > 0) {
      var allReplies = [];
      (data.replies     || []).forEach(function (item) { allReplies.push({ item: item, type: 'reply' }); });
      (data.webmentions || []).forEach(function (item) { allReplies.push({ item: item, type: 'webmention' }); });
      (data.comments    || []).forEach(function (item) { allReplies.push({ item: item, type: 'comment' }); });

      allReplies.sort(function (a, b) {
        var da = a.item.date || '';
        var db = b.item.date || '';
        return da < db ? -1 : da > db ? 1 : 0;
      });

      html += '<div id="ssh-replies-list-' + root.id + '" class="ssh-avatar-list" aria-hidden="false">';
      html += '<h3 class="ssh-section-title">' + esc(t('Replies')) + '</h3>';
      // Legend: show icon + count for each non-zero source type present
      var legendParts = [];
      if (totalFed   > 0) { legendParts.push('\uD83C\uDF10 ' + totalFed   + ' ' + esc(t('Fediverse'))); }
      if (totalWm    > 0) { legendParts.push('\uD83D\uDD17 ' + totalWm    + ' ' + esc(t('webmentions'))); }
      if (totalLocal > 0) { legendParts.push('\uD83D\uDCAC ' + totalLocal + ' ' + esc(t('local'))); }
      if (legendParts.length > 1) {
        html += '<p class="ssh-reply-legend">' + legendParts.join(' <span class="ssh-legend-sep">\u00B7</span> ') + '</p>';
      }
      allReplies.forEach(function (entry) { html += renderComment(entry.item, entry.type); });
      html += '</div>';
    }

    // Comment form (always visible)
    html += '<section class="ssh-section ssh-comments-section">';
    html += renderCommentForm(root.id, preview);
    html += '</section>';

    root.innerHTML = html;
    attachEventListeners(root, pageUrl, api, preview);
  }

  // ---------------------------------------------------------------------------
  // Render helpers
  // ---------------------------------------------------------------------------

  function renderAvatar(item) {
    var name = esc(item.author || '?');
    var url  = item.author_url ? esc(item.author_url) : '#';
    var img  = item.author_avatar
      ? '<img src="' + esc(item.author_avatar) + '" alt="' + name + '" width="40" height="40" loading="lazy">'
      : '<span class="ssh-avatar-placeholder">' + esc((item.author || '?')[0].toUpperCase()) + '</span>';
    return '<a href="' + url + '" class="ssh-avatar-link" title="' + name + '" target="_blank" rel="noopener noreferrer">' + img + '</a>';
  }

  function renderComment(item, type) {
    var name    = esc(item.author || t('Anonymous'));
    var url     = item.author_url ? esc(item.author_url) : null;
    var content = esc(item.content || '');
    var date    = item.date ? formatDate(item.date) : '';
    var source  = item.source ? esc(item.source) : null;

    var authorHtml = url
      ? '<a href="' + url + '" target="_blank" rel="noopener noreferrer nofollow">' + name + '</a>'
      : name;

    // Type icon: 🌐 Fediverse reply, 🔗 webmention, 💬 local comment
    var iconMap  = { reply: '\uD83C\uDF10', webmention: '\uD83D\uDD17', comment: '\uD83D\uDCAC' };
    var labelMap = { reply: t('Fediverse reply'), webmention: t('Webmention'), comment: t('Comment') };
    var iconHtml = iconMap[type]
      ? '<span class="ssh-reply-type-icon" aria-label="' + esc(labelMap[type]) + '" title="' + esc(labelMap[type]) + '">' + iconMap[type] + '</span>'
      : '';

    var html = '<article class="ssh-comment ssh-comment-' + type + (item.pending ? ' ssh-comment-pending' : '') + '">';
    html += '<header class="ssh-comment-header">';
    html += iconHtml;
    html += '<div class="ssh-comment-avatar">' + renderAvatar(item) + '</div>';
    html += '<div class="ssh-comment-meta">';
    html += '<span class="ssh-comment-author">' + authorHtml + '</span>';
    if (date) {
      // Link the date to the source post/page for fediverse replies and webmentions.
      var timeEl = '<time class="ssh-comment-date" datetime="' + esc(item.date) + '">' + esc(date) + '</time>';
      html += ' ' + (source
        ? '<a class="ssh-comment-date-link" href="' + source + '" target="_blank" rel="noopener noreferrer">' + timeEl + '</a>'
        : timeEl);
    }
    html += '</div></header>';
    if (content) {
      html += '<div class="ssh-comment-content">' + content + '</div>';
    }
    if (item.pending) {
      html += '<p class="ssh-comment-pending-notice">\u23F3 ' + esc(t('Awaiting moderation')) + '</p>';
    }
    html += '</article>';
    return html;
  }

  function renderCommentForm(rootId, preview) {
    var pfx = rootId ? rootId + '-' : '';
    var previewNotice = preview
      ? '<div class="ssh-preview-notice">\u26A0\uFE0F ' + esc(t('Admin preview \u2014 comments submitted here create real pending entries in WordPress.')) + '</div>'
      : '';
    return '<div class="ssh-form-wrap">' +
      previewNotice +
      '<h4 class="ssh-form-title">' + esc(t('Add a comment')) + '</h4>' +
      '<form class="ssh-comment-form" novalidate>' +
        '<div class="ssh-field">' +
          '<label for="' + pfx + 'ssh-author-name">' + esc(t('Name')) + ' <span aria-hidden="true">*</span></label>' +
          '<input type="text" id="' + pfx + 'ssh-author-name" name="author_name" required autocomplete="name" maxlength="245">' +
        '</div>' +
        '<div class="ssh-field">' +
          '<label for="' + pfx + 'ssh-author-email">' + esc(t('Email')) + ' <span aria-hidden="true">*</span> <span class="ssh-field-hint">(' + esc(t('not published')) + ')</span></label>' +
          '<input type="email" id="' + pfx + 'ssh-author-email" name="author_email" required autocomplete="email" maxlength="100">' +
        '</div>' +
        '<div class="ssh-field">' +
          '<label for="' + pfx + 'ssh-author-url">' + esc(t('Website')) + '</label>' +
          '<input type="url" id="' + pfx + 'ssh-author-url" name="author_url" autocomplete="url" maxlength="200" placeholder="https://">' +
        '</div>' +
        '<div class="ssh-field">' +
          '<label for="' + pfx + 'ssh-content">' + esc(t('Comment')) + ' <span aria-hidden="true">*</span></label>' +
          '<textarea id="' + pfx + 'ssh-content" name="content" required rows="5" maxlength="65525"></textarea>' +
        '</div>' +
        '<div class="ssh-form-actions">' +
          '<button type="submit" class="ssh-submit-btn">' + esc(t('Post comment')) + '</button>' +
        '</div>' +
        '<div class="ssh-form-status" role="alert" aria-live="polite"></div>' +
      '</form>' +
    '</div>';
  }

  // ---------------------------------------------------------------------------
  // Event listeners
  // ---------------------------------------------------------------------------

  function attachEventListeners(root, pageUrl, api, preview) {
    var toggles = root.querySelectorAll('.ssh-reaction-toggle');
    for (var i = 0; i < toggles.length; i++) {
      toggles[i].addEventListener('click', handleToggle);
    }

    var form = root.querySelector('.ssh-comment-form');
    if (form) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        handleCommentSubmit(form, pageUrl, api, preview);
      });
    }

    var createBtn = root.querySelector('.ssh-admin-create');
    if (createBtn) {
      createBtn.addEventListener('click', function () {
        handleCreateStaticPage(createBtn, pageUrl, api);
      });
    }
  }

  function adminEditLinkHtml(editUrl) {
    return '<a class="ssh-admin-indicator ssh-admin-exists" href="' + esc(editUrl) + '" target="_blank" rel="noopener noreferrer" title="' + esc(t('Edit static page in WordPress')) + '">' +
      '\uD83D\uDCC4 ' + esc(t('Edit on Social Hub')) + '</a>';
  }

  function adminCreateBtnHtml() {
    return '<button class="ssh-admin-indicator ssh-admin-create">' +
      '\u2795 ' + esc(t('Create on Social Hub')) + '</button>';
  }

  function handleCreateStaticPage(btn, pageUrl, api) {
    btn.disabled = true;
    btn.textContent = t('Creating\u2026');

    fetch(api + '/static-pages', {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ url: pageUrl }),
    })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (!data.post_id) { throw new Error('no post_id'); }
        var tmp = document.createElement('div');
        tmp.innerHTML = adminEditLinkHtml(data.edit_url);
        btn.parentNode.replaceChild(tmp.firstChild, btn);
      })
      .catch(function () {
        btn.disabled = false;
        var tmp = document.createElement('div');
        tmp.innerHTML = adminCreateBtnHtml();
        var fresh = tmp.firstChild;
        btn.className = fresh.className;
        btn.textContent = fresh.textContent;
      });
  }

  function hasWpSettingsCookie() {
    // wp-settings-time-{userId} is set by WordPress for every logged-in user
    // and is readable by JS (not HttpOnly), making it a reliable session proxy.
    return document.cookie.split(';').some(function (c) {
      return c.trim().indexOf('wp-settings-time') === 0;
    });
  }

  function handleToggle(e) {
    var btn      = e.currentTarget;
    var targetId = btn.getAttribute('data-target');
    var panel    = document.getElementById(targetId);
    if (!panel) { return; }
    var expanded = btn.getAttribute('aria-expanded') === 'true';
    btn.setAttribute('aria-expanded', expanded ? 'false' : 'true');
    panel.setAttribute('aria-hidden', expanded ? 'true' : 'false');
    panel.classList[expanded ? 'add' : 'remove']('ssh-collapsed');
  }

  function handleCommentSubmit(form, pageUrl, api, preview) {
    var statusEl  = form.querySelector('.ssh-form-status');
    var submitBtn = form.querySelector('.ssh-submit-btn');
    var nameEl    = form.querySelector('[name="author_name"]');
    var emailEl   = form.querySelector('[name="author_email"]');
    var contentEl = form.querySelector('[name="content"]');

    if (!nameEl.value.trim()) {
      setStatus(statusEl, 'error', t('Please enter your name.')); nameEl.focus(); return;
    }
    if (!emailEl.value.trim() || !isValidEmail(emailEl.value)) {
      setStatus(statusEl, 'error', t('Please enter a valid email address.')); emailEl.focus(); return;
    }
    if (!contentEl.value.trim()) {
      setStatus(statusEl, 'error', t('Please write something in your comment.')); contentEl.focus(); return;
    }

    // In admin preview mode, show what would happen without actually posting.
    if (preview) {
      setStatus(statusEl, 'success',
        t('Preview: your comment would be submitted for moderation. Fill in the form on your static site to post for real.'));
      return;
    }

    submitBtn.disabled = true;
    submitBtn.textContent = t('Posting\u2026');
    setStatus(statusEl, '', '');

    var payload = {
      url:          pageUrl,
      author_name:  nameEl.value.trim(),
      author_email: emailEl.value.trim(),
      author_url:   (form.querySelector('[name="author_url"]') || {}).value || '',
      content:      contentEl.value.trim(),
    };

    submitComment(payload, api, function (err, data) {
      submitBtn.disabled = false;
      submitBtn.textContent = t('Post comment');
      if (err) {
        setStatus(statusEl, 'error', (err && err.message) ? err.message : t('Something went wrong. Please try again.'));
        return;
      }

      // Hide the entire form wrap (removes "Add a comment" heading too) and
      // replace it with a brief confirmation note.
      var formWrap = form.closest ? form.closest('.ssh-form-wrap') : form.parentNode;
      if (formWrap) {
        formWrap.style.display = 'none';
        var note = document.createElement('p');
        note.className = 'ssh-comment-submitted';
        note.textContent = data.message || t('Your comment has been received.');
        formWrap.parentNode.insertBefore(note, formWrap.nextSibling);
      }

      // Insert the submitted comment into the replies list so the visitor
      // immediately sees their comment (with a pending badge if awaiting moderation).
      if (data && data.comment) {
        var commentHtml = renderComment(data.comment, 'comment');
        var rootEl = form.closest ? form.closest('.ssh-widget') : null;
        var rootId = rootEl ? rootEl.id : null;
        var repliesPanel = rootId ? document.getElementById('ssh-replies-list-' + rootId) : null;
        if (repliesPanel) {
          repliesPanel.insertAdjacentHTML('beforeend', commentHtml);
        } else {
          // No replies section yet — create one before the form section.
          var formSection = formWrap ? formWrap.parentNode : null;
          if (formSection) {
            var newPanel = document.createElement('div');
            if (rootId) { newPanel.id = 'ssh-replies-list-' + rootId; }
            newPanel.className = 'ssh-avatar-list';
            newPanel.setAttribute('aria-hidden', 'false');
            newPanel.innerHTML = '<h3 class="ssh-section-title">' + esc(t('Replies')) + '</h3>' + commentHtml;
            formSection.insertBefore(newPanel, formSection.firstChild);
          }
        }
      }
    });
  }

  function setStatus(el, type, message) {
    el.className = 'ssh-form-status' + (type ? ' ssh-status-' + type : '');
    el.textContent = message;
  }

  // ---------------------------------------------------------------------------
  // Styles
  // ---------------------------------------------------------------------------

  // ---------------------------------------------------------------------------
  // Utilities
  // ---------------------------------------------------------------------------

  function esc(str) {
    return String(str)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function t(str) { return str; }

  function isValidEmail(email) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
  }

  function formatDate(iso) {
    try {
      var d = new Date(iso + (iso.indexOf('Z') === -1 ? 'Z' : ''));
      return d.toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' });
    } catch (e) { return iso; }
  }

})();
