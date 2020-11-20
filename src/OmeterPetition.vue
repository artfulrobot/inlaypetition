<template>
  <div class="ipetometer" ref="ometer">
    <span class="ipetometer__bignum">{{ count.toLocaleString() }}</span>
    <span class="ipetometer__words">{{stmt}}</span>
    <span class="ipetometer__target">Target {{target}}</span>
    <div class="ipetometer__domain" >
      <div class="ipetometer__bar" :style="barStyle"></div>
    </div>
  </div>
</template>
<script>
export default {
  props: ['count', 'stmt', 'target'],
  data() {
    return {
      animStart: false,
      step: 0,

      containerSize:false,
      debounce: false,
    };
  },
  computed:{
    barStyle() {
      var s = this.step;
      s = s*s;
      return {
        width: (s * this.count / this.target * 100) + '%'
      };
    },
  },
  mounted() {
    var observer = new IntersectionObserver(this.handleIntersectionChange.bind(this), {
      // root: this.$refs.treesContainer,
      // rootMargin: '0px',
      threshold: 1.0
    });
    observer.observe(this.$refs.ometer);
  },
  methods:{
    handleIntersectionChange(entries, observer) {
      entries.forEach(e => {
        if (e.isIntersecting) {
          this.startAnimation();
        }
      });
    },
    startAnimation() {
      this.animStart = false;
      window.requestAnimationFrame(this.animate.bind(this));
    },
    animate(t) {
      if (!this.animStart) {
        this.animStart = t;
      }
      // Allow 1 s for the animation.
      this.step = Math.min(1, (t - this.animStart) / 1000);
      if (this.step < 1) {
        window.requestAnimationFrame(this.animate.bind(this));
      }
    }
  }
}
</script>
<style lang="scss">
@import 'konp';

.ipetometer {
  display: flex;
  flex-wrap:wrap;
  align-items: center;
  justify-content: space-between;
  background: $blue;
  line-height: 1;
  padding: 1rem;
  color: white;
  margin-bottom: 1rem;
  font-weight: bold;

  .ipetometer__domain {
    margin-top: 1rem;
    flex: 0 0 100%;
    background: rgba(255,255,255, 0.2);
  }
  .ipetometer__bar {
    background: #fc0;
    height: 1rem;
  }

  .ipetometer__bignum {
    flex: 0 0 auto;
    padding-right: 1rem;
    font-size:3rem;
  }
  .ipetometer__words {
    flex: 1 1 auto;
    font-size: 1rem;
  }
  .ipetometer__target {
    flex: 0 0 auto;
    font-size: 1rem;
  }
}
</style>
