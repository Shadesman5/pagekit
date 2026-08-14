const ENTITIES = {
  '&': '&amp;',
  '<': '&lt;',
  '>': '&gt;',
  '"': '&quot;',
  "'": '&#39;'
};

/**
 * A notification is written into the panel as markup, while what it says comes
 * from package manifests, file names and server responses - none of which owes
 * anyone valid HTML. So a message is shown as the text it is.
 */
function escapeHtml(message) {
  return String(message).replace(/[&<>"']/g, character => ENTITIES[character]);
}

export default function (Vue) {
  Vue.prototype.$notify = function () {
    const args = arguments;
    const messages = document.getElementsByClassName('pk-system-messages')[0];
    const UIkit = window.UIkit || {};
    const message = args[0] ? this.$trans(args[0]) : 'Unrecognized error.';
    const status = args[1] ? args[1] : 'primary';

    if (UIkit.notification) {
      UIkit.notification({
        message: escapeHtml(message),
        status,
        pos: 'top-center'
      });
    } else if (messages) {
      const notice = document.createElement('div');
      const text = document.createElement('p');

      notice.setAttribute('uk-alert', '');
      text.className = `uk-alert-${status}`;
      text.textContent = message;
      notice.append(text);

      messages.replaceChildren(notice);
    }
  };
}
