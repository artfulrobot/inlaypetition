<template>
  <div class="ipetometer" ref="ometer">
    <span class="ipetometer__bignum">{{ count.toLocaleString() }}</span>
    <span class="ipetometer__words">{{stmt}} (Target {{target}})</span>
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
      return {
        width: (this.step * this.count / this.target * 100) + '%'
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
      console.log("handleIntersectionChange");
      entries.forEach(e => {
        if (e.isIntersecting) {
          this.animStart = false;
          window.requestAnimationFrame(this.animate.bind(this));
        }
      });
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
  padding: 1rem;
  color: white;
  margin-bottom: 1rem;
  font-weight: bold;

  .ipetometer__domain {
    flex: 0 0 100%;
    background: #eee;
  }
  .ipetometer__bar {
    background: #fc0;
    height: 2rem;
  }

  .ipetometer__bignum {
    flex: 0 0 auto;
    padding-right: 1rem;
    font-size:3rem;
  }
  .ipetometer__words {
    flex: 0 1 auto;
    font-size: 1rem;
  }
}
</style>
