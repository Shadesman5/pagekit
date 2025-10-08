<template>
    <div>
        <h1>MENUCARDS WORKS!</h1>
        <p>If you see this, Vue is working.</p>
        <button class="uk-button uk-button-primary" @click="testClick">Test Button</button>
        <p v-if="clicked">Button was clicked!</p>
    </div>
</template>

<script>
export default {
    name: 'MenuListSimple',
    
    data() {
        return {
            clicked: false
        };
    },
    
    created() {
        console.log('MenuListSimple: Component created!');
    },
    
    methods: {
        testClick() {
            console.log('Button clicked!');
            this.clicked = true;
            this.$notify('Button works!', 'success');
        }
    }
};
</script>
