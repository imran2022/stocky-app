import { createApp } from 'vue';
import VueApexCharts from 'vue3-apexcharts';
import RealTimeSalesDisplay from './RealTimeSalesDisplay.vue';

createApp(RealTimeSalesDisplay)
    .component('apexchart', VueApexCharts)
    .mount('#real-time-sales-display');
