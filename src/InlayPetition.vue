<template>
  <div style="overflow:hidden;" class="inlay-petition">

    <h2 v-if="inlay.initData.publicTitle">{{inlay.initData.publicTitle}}</h2>

    <ometer-petition
      v-if="inlay.initData.uxMode === 'petition'"
      :count="inlay.initData.count"
      stmt="Signatures"
      :target="target"
      ></ometer-petition>

    <form action='#' @submit.prevent="submitForm" v-if="stage === 'form'">
      <div class="ipet-intro" v-html="inlay.initData.introHTML" v-if="inlay.initData.introHTML"></div>

      <div class="ipet-2-cols">
        <div class="ipet-input-wrapper">
          <label :for="myId + 'fname'" >First name</label>
          <input
            required
            type="text"
            :id="myId + 'fname'"
            :name="first_name"
            :ref="first_name"
            :disabled="$root.submissionRunning"
            v-model="first_name"
            />
        </div>
        <div class="ipet-input-wrapper">
          <label :for="myId + 'lname'" >Last name</label>
          <input
            required
            type="text"
            :id="myId + 'lname'"
            :name="last_name"
            :ref="last_name"
            :disabled="$root.submissionRunning"
            v-model="last_name"
            />
        </div>
      </div>

      <div class="ipet-input-wrapper">
        <label :for="myId + 'email'" >Email</label>
        <input
          required
          type="email"
          :id="myId + 'email'"
          :name="email"
          :ref="email"
          :disabled="$root.submissionRunning"
          v-model="email"
          />
      </div>

      <div class="ipet-preoptin"
        v-if="inlay.initData.preOptinHTML"
        v-html="inlay.initData.preOptinHTML"></div>

      <div class="ipet-optins" v-if="inlay.initData.optinMode === 'radios'" >
        <div class="option">
          <input type="radio"
                 name="optin"
                 required
                 :id="myId + 'ipet-optin-yes'"
                 value="yes"
                 v-model="optin"
                 />
          <label :for="myId + 'ipet-optin-yes'"> {{inlay.initData.optinYesText}}</label>
        </div>
        <div class="option">
          <input type="radio"
                 name="optin"
                 required
                 :id="myId + 'ipet-optin-no'"
                 value="no"
                 v-model="optin"
                 />
          <label :for="myId + 'ipet-optin-no'"> {{inlay.initData.optinNoText}}</label>
        </div>
      </div>

      <div class="ipet-optins" v-if="inlay.initData.optinMode === 'checkbox'" >
        <div class="option">
          <input type="checkbox"
                 name="optin"
                 required
                 :id="myId + 'ipet-optin-cb'"
                 value="yes"
                 v-model="optin"
                 />
          <label :for="myId + 'ipet-optin-cb'"> {{inlay.initData.optinYesText}}</label>
        </div>
      </div>

      <div class="ipet-smallprint"
        v-if="inlay.initData.smallprintHTML"
        v-html="inlay.initData.smallprintHTML"></div>
      <!--
      <div v-if="inlay.initData.phoneAsk">
        <label :for="myId + 'phone'" >Phone</label>
        <input
          type="text"
          :id="myId + 'phone'"
          :name="phone"
          :ref="phone"
          :disabled="$root.submissionRunning"
          v-model="phone"
          />
      </div>
      -->

      <div class="ipet-submit">
        <button
         @click="wantsToSubmit"
         :disabled="$root.submissionRunning"
          class="submit"
          >{{ $root.submissionRunning ? "Please wait.." : inlay.initData.submitButtonText }}</button>
        <inlay-progress ref="progress"></inlay-progress>
      </div>

    </form>

    <div v-if="stage === 'social'">
      <div v-html="inlay.initData.shareAskHTML"></div>
      <inlay-socials
        :socials="inlay.initData.socials"
        icons="1"
        @clicked="stage='final'"
        ></inlay-socials>
      <p><a href @click.prevent="stage='final'">Skip</a></p>
    </div>

    <div v-if="stage === 'final'">
      <div v-html="inlay.initData.finalHTML"></div>
    </div>
  </div>
</template>
<style lang="scss">
.inlay-petition {
  @import 'konp';

  background-color: $lightGrey;
  padding: 1rem;

  &, * {
    box-sizing: border-box;
  }

  &>h2 {
    margin-top: 0;
  }

  .ipet-2-cols {
    margin-left: -1rem;
    margin-right: -1rem;
    display: flex;
    flex-wrap: wrap;

    &>div {
      flex: 1 0 18rem;
      padding: 0 1rem;
    }
  }

  .ipet-input-wrapper {
    margin-bottom: 1rem;
  }
  input[type="text"],
  input[type="email"],
  label {
    line-height:1;
    margin: 0;
    font-size: 1.1rem;
  }

  label {
    display: block;
    padding: 0.75rem 0;
  }

  input[type="text"],
  input[type="email"]
  {
    padding: 0.75rem 1rem;
    background: white;
    width: 100%;
  }

  .ipet-optins .option {
    position: relative;
    margin-left: 2rem;

    input[type="radio"] {
      position: absolute;
      margin-left: -2rem;
      margin-top: 0.75rem;
    }
  }

  .ipet-submit {
    text-align: center;

    button {
      font-size: 1.1rem;
    }
  }

  a.button {
    display: inline-block;
    margin-right: 1rem;
    margin-bottom: 1rem;
    padding: 0.5rem 1rem;
    border-radius:0;
    border: none;
    background-color: #0967CB;
    color: #fc0;
    font-weight: bold;
    text-decoration: none;

    &:hover {
      background: #fc0;
      color: #0967CB;
    }
  }
}
</style>
<script>
import InlayProgress from './InlayProgress.vue';
import InlaySocials from './InlaySocials.vue';
import OmeterPetition from './OmeterPetition.vue';
// import 'vue-select/dist/vue-select.css';
// import vSelect from 'vue-select';

export default {
  props: ['inlay'],
  components: {InlayProgress, InlaySocials, OmeterPetition},
  data() {
    const d = {
      stage: 'form',
      myId: this.$root.getNextId(),

      first_name: '',
      last_name: '',
      email: '',
      phone: '',
      optin: '',
    };
    return d;
  },
  computed: {
    target() {
      // always at 70%
      var chunk = 10;
      if (this.inlay.initData.count > 1000) {
        chunk = 1000;
      }
      else if (this.inlay.initData.count > 100) {
        chunk = 100;
      }
      return Math.floor((this.inlay.initData.count / 0.7) / chunk) * chunk + chunk;
    },
    countSigners() {
      return this.inlay.initData.count + (this.stage !== 'form' ? 1 : 0);
    }
  },
  methods: {
    wantsToSubmit() {
      // validate all fields.
    },
    submitForm() {
      // Form is valid according to browser.
      this.$root.submissionRunning = true;
      const d = {
        first_name: this.first_name,
        last_name: this.last_name,
        email: this.email,
        phone: this.phone,
        location: window.location.href
      };

      const progress = this.$refs.progress;
      // Allow 5s to get 20% through in first submit.
      progress.startTimer(5, 20, {reset: 1});
      this.inlay.request({method: 'post', body: d})
        .then(r => {
          if (r.token) {
            d.token = r.token;
            // Allow 5s for our wait, taking us to 80% linearly.
            progress.startTimer(5, 80, {easing: false});
            // Force 5s wait for the token to become valid
            return new Promise((resolve, reject) => {
              window.setTimeout(resolve, 5000);
            });
          }
          else {
            console.warn("unexpected resonse", r);
            throw (r.error || 'Unknown error');
          }
        })
        .then(() => {
          // Finally allow 5s for the final submission to 100%
          progress.startTimer(5, 100);
          return this.inlay.request({method: 'post', body: d});
        })
        .then(r => {
          if (r.error) {
            throw (r.error);
          }
          this.stage = 'social';
          // Increment totals.
          this.inlay.initData.count += 1;
          progress.cancelTimer();
        })
        .catch(e => {
          console.error(e);
          if (typeof e === 'String') {
            alert(e);
          }
          else {
            alert("Unexpected error");
          }
          this.$root.submissionRunning = false;
          progress.cancelTimer();
        });
    }
  }
}
</script>
