import Vue from 'vue';
import InlayPetition from './InlayPetition.vue';

(() => {
  console.log("here");
  if (!window.inlayPetitionInit) {
    console.log("inlayPetitionInit does not exist, will create it now");
    // This is the first time this *type* of Inlay has been encountered.
    // We need to define anything global here.

    // Create the boot function.
    window.inlayPetitionInit = inlay => {
      const inlayNode = document.createElement('div');
      inlay.script.insertAdjacentElement('afterend', inlayNode);
      /* eslint no-unused-vars: 0 */
      // This is the root app.
      console.debug(inlay);
      const app = new Vue({
        el: inlayNode,
        data() {
          var d = {
            inlay,
            formID: 0,
            submissionRunning: false
          };
          return d;
        },
        render: h => h(InlayPetition, {props: {inlay}}),
        methods: {
          getNextId() {
            this.formID++;
            return `i${this.inlay.publicID}-${this.formID}`;
          }
        }
      });
    };
  }
})();
