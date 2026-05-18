<template>
  <ion-modal :is-open="isOpen" @did-dismiss="onDismiss">
    <ion-header>
      <ion-toolbar>
        <ion-title>Scan document</ion-title>
        <ion-buttons slot="end">
          <ion-button @click="onCancel">Close</ion-button>
        </ion-buttons>
      </ion-toolbar>
    </ion-header>
    <ion-content class="ion-padding">
      <p style="text-align:center;color:#475569;margin-top:24px;">
        Live document scanner is unavailable in this build.
      </p>
      <p style="text-align:center;color:#64748B;font-size:13px;">
        Use the manual paste flow instead.
      </p>
      <div style="text-align:center;margin-top:24px;">
        <ion-button @click="onManual">Enter MRZ manually</ion-button>
      </div>
    </ion-content>
  </ion-modal>
</template>

<script setup>
import {
  IonModal, IonHeader, IonToolbar, IonTitle, IonButtons, IonButton, IonContent,
} from '@ionic/vue';

defineProps({
  isOpen:  { type: Boolean, default: false },
  docType: { type: String,  default: 'passport' },
});
const emit = defineEmits(['result', 'cancel', 'manual']);

function onCancel()  { emit('cancel'); }
function onManual()  { emit('manual'); }
function onDismiss() { emit('cancel'); }
</script>
