import { useRef, useState } from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { CameraView, useCameraPermissions } from 'expo-camera';
import { router } from 'expo-router';
import { colors } from '../lib/theme';
import { Button } from '../components/ui';
import { setScannedCode } from '../lib/scan';

export default function Scanner() {
  const [perm, ask] = useCameraPermissions();
  const done = useRef(false);
  const [, force] = useState(0);

  if (!perm) return <View />;
  if (!perm.granted) {
    return (
      <View style={s.center}>
        <Text style={{ textAlign: 'center', marginBottom: 16 }}>Serve il permesso della fotocamera per scansionare i QR di ritiro.</Text>
        <Button title="Consenti fotocamera" onPress={() => { ask(); force((n) => n + 1); }} />
      </View>
    );
  }
  return (
    <View style={{ flex: 1 }}>
      <CameraView
        style={{ flex: 1 }}
        barcodeScannerSettings={{ barcodeTypes: ['qr'] }}
        onBarcodeScanned={({ data }) => {
          if (done.current) return;
          done.current = true;
          setScannedCode(data);
          router.back();
        }}
      />
      <View style={s.hint}><Text style={{ color: '#fff', fontWeight: '600' }}>Inquadra il QR del cliente</Text></View>
    </View>
  );
}

const s = StyleSheet.create({
  center: { flex: 1, justifyContent: 'center', padding: 24, backgroundColor: colors.cream },
  hint: { position: 'absolute', bottom: 40, alignSelf: 'center', backgroundColor: 'rgba(0,0,0,.6)', paddingHorizontal: 16, paddingVertical: 8, borderRadius: 99 },
});
