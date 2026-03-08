/**
 * Static Social Hub — embeddable JS widget
 *
 * Auto-embed usage on your static site:
 *
 *   <div id="ssh-comments"></div>
 *   <script src="https://[wp-host]/wp-content/plugins/static-social-hub/assets/static-social-hub.js"
 *           data-api="https://[wp-host]/wp-json/static-social-hub`/v1"></script>
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

    injectStyles(theme);
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

    var totalLikes   = (data.likes   || []).length;
    var totalBoosts  = (data.boosts  || []).length;
    var totalReplies = (data.replies || []).length;

    // Reactions bar
    if (totalLikes + totalBoosts + totalReplies > 0) {
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
      if (totalReplies > 0) {
        html += '<button class="ssh-reaction-toggle" aria-expanded="false" data-target="ssh-replies-list-' + root.id + '">';
        html += '\uD83D\uDCAC <span class="ssh-count">' + totalReplies + '</span> ' + esc(totalReplies === 1 ? t('fediverse reply') : t('fediverse replies'));
        html += '</button>';
      }
      html += '</div>';
    }

    // Likes panel
    if (totalLikes > 0) {
      html += '<div id="ssh-likes-list-' + root.id + '" class="ssh-avatar-list ssh-collapsed" aria-hidden="true">';
      html += '<h3 class="ssh-section-title">' + esc(t('Likes')) + '</h3>';
      html += '<ul class="ssh-avatars">';
      (data.likes || []).forEach(function (item) { html += '<li>' + renderAvatar(item) + '</li>'; });
      html += '</ul></div>';
    }

    // Boosts panel
    if (totalBoosts > 0) {
      html += '<div id="ssh-boosts-list-' + root.id + '" class="ssh-avatar-list ssh-collapsed" aria-hidden="true">';
      html += '<h3 class="ssh-section-title">' + esc(t('Boosts')) + '</h3>';
      html += '<ul class="ssh-avatars">';
      (data.boosts || []).forEach(function (item) { html += '<li>' + renderAvatar(item) + '</li>'; });
      html += '</ul></div>';
    }

    // Fediverse replies panel
    if (totalReplies > 0) {
      html += '<div id="ssh-replies-list-' + root.id + '" class="ssh-avatar-list ssh-collapsed" aria-hidden="true">';
      html += '<h3 class="ssh-section-title">' + esc(t('Fediverse Replies')) + '</h3>';
      (data.replies || []).forEach(function (item) { html += renderComment(item, 'reply'); });
      html += '</div>';
    }

    // Webmentions
    if ((data.webmentions || []).length > 0) {
      html += '<section class="ssh-section ssh-webmentions">';
      html += '<h3 class="ssh-section-title">' + esc(t('Webmentions')) + '</h3>';
      (data.webmentions || []).forEach(function (item) { html += renderComment(item, 'webmention'); });
      html += '</section>';
    }

    // Comments + form
    html += '<section class="ssh-section ssh-comments-section">';
    var commentCount = (data.comments || []).length;
    html += '<h3 class="ssh-section-title">';
    html += esc(commentCount === 0 ? t('Leave a comment') : (commentCount === 1 ? t('1 comment') : commentCount + ' ' + t('comments')));
    html += '</h3>';
    if (commentCount > 0) {
      html += '<ol class="ssh-comment-list">';
      (data.comments || []).forEach(function (item) { html += '<li>' + renderComment(item, 'comment') + '</li>'; });
      html += '</ol>';
    }
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

    var html = '<article class="ssh-comment ssh-comment-' + type + '">';
    html += '<header class="ssh-comment-header">';
    html += '<div class="ssh-comment-avatar">' + renderAvatar(item) + '</div>';
    html += '<div class="ssh-comment-meta">';
    html += '<span class="ssh-comment-author">' + authorHtml + '</span>';
    if (date) {
      html += ' <time class="ssh-comment-date" datetime="' + esc(item.date) + '">' + esc(date) + '</time>';
    }
    if (source) {
      html += ' <a class="ssh-webmention-source" href="' + source + '" target="_blank" rel="noopener noreferrer">' + esc(t('\u2192 source')) + '</a>';
    }
    html += '</div></header>';
    if (content) {
      html += '<div class="ssh-comment-content">' + content + '</div>';
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
      form.style.display = 'none';
      setStatus(statusEl, 'success', data.message || t('Your comment has been received.'));
    });
  }

  function setStatus(el, type, message) {
    el.className = 'ssh-form-status' + (type ? ' ssh-status-' + type : '');
    el.textContent = message;
  }

  // ---------------------------------------------------------------------------
  // Styles
  // ---------------------------------------------------------------------------

  function injectStyles(theme) {
    if (document.getElementById('ssh-widget-styles')) { return; }

    var darkVars = [
      '--ssh-bg:#1a1a1a', '--ssh-bg2:#252525', '--ssh-border:#3a3a3a',
      '--ssh-text:#e8e8e8', '--ssh-text-muted:#999', '--ssh-link:#7bbfff',
      '--ssh-input-bg:#2a2a2a', '--ssh-btn-bg:#4a90d9', '--ssh-btn-text:#fff',
      '--ssh-success:#4caf50', '--ssh-error:#f44336',
    ].join(';');

    var lightVars = [
      '--ssh-bg:#fff', '--ssh-bg2:#f9f9f9', '--ssh-border:#e0e0e0',
      '--ssh-text:#111', '--ssh-text-muted:#666', '--ssh-link:#0073aa',
      '--ssh-input-bg:#fff', '--ssh-btn-bg:#0073aa', '--ssh-btn-text:#fff',
      '--ssh-success:#2e7d32', '--ssh-error:#c62828',
    ].join(';');

    var css = '';
    if (theme === 'dark')       { css += '.ssh-widget{' + darkVars + '}'; }
    else if (theme === 'light') { css += '.ssh-widget{' + lightVars + '}'; }
    else {
      css += '.ssh-widget{' + lightVars + '}';
      css += '@media(prefers-color-scheme:dark){.ssh-widget{' + darkVars + '}}';
    }

    css += [
      '.ssh-widget{font-family:inherit;font-size:1rem;color:var(--ssh-text);line-height:1.6;max-width:100%;box-sizing:border-box}',
      '.ssh-widget *{box-sizing:border-box}',
      '.ssh-loading{padding:1em;color:var(--ssh-text-muted);text-align:center}',
      '.ssh-error{color:var(--ssh-error);padding:.5em 0}',
      '.ssh-reactions-bar{display:flex;flex-wrap:wrap;gap:.5em;margin-bottom:1em;padding:.75em;background:var(--ssh-bg2);border:1px solid var(--ssh-border);border-radius:6px}',
      '.ssh-reaction-toggle{background:none;border:1px solid var(--ssh-border);border-radius:4px;padding:.25em .6em;cursor:pointer;color:var(--ssh-text);font-size:.9rem;transition:background .15s}',
      '.ssh-reaction-toggle:hover,.ssh-reaction-toggle[aria-expanded="true"]{background:var(--ssh-border)}',
      '.ssh-count{font-weight:600}',
      '.ssh-avatar-list{overflow:hidden}',
      '.ssh-collapsed{display:none}',
      '.ssh-avatars{list-style:none;margin:0;padding:.5em 0;display:flex;flex-wrap:wrap;gap:.4em}',
      '.ssh-avatar-link{display:inline-block;border-radius:50%;overflow:hidden;width:40px;height:40px;transition:opacity .15s}',
      '.ssh-avatar-link:hover{opacity:.85}',
      '.ssh-avatar-link img{width:40px;height:40px;display:block;object-fit:cover}',
      '.ssh-avatar-placeholder{display:flex;align-items:center;justify-content:center;width:40px;height:40px;background:var(--ssh-border);color:var(--ssh-text-muted);font-weight:600;font-size:1rem;border-radius:50%}',
      '.ssh-section{margin-top:1.5em}',
      '.ssh-section-title{font-size:1.05rem;font-weight:600;margin:0 0 .75em;padding-bottom:.4em;border-bottom:2px solid var(--ssh-border);color:var(--ssh-text)}',
      '.ssh-comment-list{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:1em}',
      '.ssh-comment{padding:.75em;border:1px solid var(--ssh-border);border-radius:6px;background:var(--ssh-bg2)}',
      '.ssh-comment-header{display:flex;align-items:center;gap:.75em;margin-bottom:.5em}',
      '.ssh-comment-avatar{flex-shrink:0}',
      '.ssh-comment-meta{font-size:.9rem;color:var(--ssh-text-muted)}',
      '.ssh-comment-author{font-weight:600;color:var(--ssh-text)}',
      '.ssh-comment-author a{color:var(--ssh-link);text-decoration:none}',
      '.ssh-comment-author a:hover{text-decoration:underline}',
      '.ssh-comment-date{margin-left:.4em;font-size:.85em}',
      '.ssh-webmention-source{margin-left:.4em;font-size:.85em;color:var(--ssh-link)}',
      '.ssh-comment-content{font-size:.95rem;margin:0;white-space:pre-wrap;word-break:break-word}',
      '.ssh-form-wrap{margin-top:1.5em;padding:1em;border:1px solid var(--ssh-border);border-radius:6px;background:var(--ssh-bg2)}',
      '.ssh-form-title{font-size:1rem;font-weight:600;margin:0 0 1em;color:var(--ssh-text)}',
      '.ssh-field{margin-bottom:.85em}',
      '.ssh-field label{display:block;font-size:.9rem;font-weight:500;margin-bottom:.3em;color:var(--ssh-text)}',
      '.ssh-field-hint{font-weight:400;color:var(--ssh-text-muted)}',
      '.ssh-field input,.ssh-field textarea{width:100%;padding:.45em .65em;border:1px solid var(--ssh-border);border-radius:4px;background:var(--ssh-input-bg);color:var(--ssh-text);font-size:.95rem;font-family:inherit;outline:none;transition:border-color .15s}',
      '.ssh-field input:focus,.ssh-field textarea:focus{border-color:var(--ssh-btn-bg)}',
      '.ssh-field textarea{resize:vertical;min-height:100px}',
      '.ssh-form-actions{margin-top:.75em}',
      '.ssh-submit-btn{background:var(--ssh-btn-bg);color:var(--ssh-btn-text);border:none;padding:.5em 1.2em;border-radius:4px;cursor:pointer;font-size:.95rem;font-family:inherit;font-weight:500;transition:opacity .15s}',
      '.ssh-submit-btn:hover:not(:disabled){opacity:.9}',
      '.ssh-submit-btn:disabled{opacity:.55;cursor:not-allowed}',
      '.ssh-form-status{margin-top:.6em;font-size:.9rem;min-height:1.2em}',
      '.ssh-status-error{color:var(--ssh-error)}',
      '.ssh-status-success{color:var(--ssh-success);font-weight:500}',
      '.ssh-preview-notice{font-size:.85rem;background:#fff3cd;color:#664d03;border:1px solid #ffecb5;border-radius:4px;padding:.4em .7em;margin-bottom:1em}',
    ].join('');

    var style = document.createElement('style');
    style.id = 'ssh-widget-styles';
    style.textContent = css;
    document.head.appendChild(style);
  }

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
