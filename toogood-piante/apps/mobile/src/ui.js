import { ActivityIndicator, KeyboardAvoidingView, Platform, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';

export const colors = {
  green: '#2f7d4f',
  greenDark: '#1f5a37',
  bg: '#f6f8f5',
  text: '#1c2b22',
  muted: '#667a6d',
  border: '#cdd9d0',
  error: '#b3261e',
};

export function Screen({ children }) {
  return (
    <KeyboardAvoidingView style={{ flex: 1, backgroundColor: colors.bg }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <ScrollView contentContainerStyle={styles.screen} keyboardShouldPersistTaps="handled">
        {children}
      </ScrollView>
    </KeyboardAvoidingView>
  );
}

export const Title = ({ children }) => <Text style={styles.title}>{children}</Text>;
export const Hint = ({ children }) => <Text style={styles.hint}>{children}</Text>;
export const ErrorText = ({ children }) => (children ? <Text style={styles.error}>{children}</Text> : null);

export function Field({ label, ...props }) {
  return (
    <View style={{ marginBottom: 14 }}>
      <Text style={styles.label}>{label}</Text>
      <TextInput style={styles.input} placeholderTextColor={colors.muted} autoCapitalize="none" autoCorrect={false} {...props} />
    </View>
  );
}

export function Button({ title, onPress, loading, secondary, disabled }) {
  const off = loading || disabled;
  return (
    <Pressable
      onPress={onPress}
      disabled={off}
      style={[styles.button, secondary && styles.buttonSecondary, off && { opacity: 0.6 }]}
    >
      {loading ? <ActivityIndicator color={secondary ? colors.green : '#fff'} /> : (
        <Text style={[styles.buttonText, secondary && { color: colors.green }]}>{title}</Text>
      )}
    </Pressable>
  );
}

export const LinkText = ({ children, onPress }) => (
  <Pressable onPress={onPress} style={{ paddingVertical: 10 }}>
    <Text style={styles.link}>{children}</Text>
  </Pressable>
);

const styles = StyleSheet.create({
  screen: { padding: 24, paddingTop: 48, flexGrow: 1 },
  title: { fontSize: 26, fontWeight: '700', color: colors.text, marginBottom: 8 },
  hint: { fontSize: 15, color: colors.muted, marginBottom: 20, lineHeight: 21 },
  error: { color: colors.error, marginBottom: 12, fontSize: 14 },
  label: { fontSize: 14, color: colors.text, marginBottom: 6, fontWeight: '600' },
  input: {
    borderWidth: 1, borderColor: colors.border, borderRadius: 10, backgroundColor: '#fff',
    paddingHorizontal: 14, paddingVertical: 12, fontSize: 16, color: colors.text,
  },
  button: {
    backgroundColor: colors.green, borderRadius: 10, paddingVertical: 14, alignItems: 'center', marginTop: 6,
    borderWidth: 1, borderColor: colors.green,
  },
  buttonSecondary: { backgroundColor: 'transparent' },
  buttonText: { color: '#fff', fontSize: 16, fontWeight: '600' },
  link: { color: colors.green, fontSize: 15, textAlign: 'center', fontWeight: '600' },
});
