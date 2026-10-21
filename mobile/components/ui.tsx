import { ReactNode } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, TextInput, TextInputProps, View, ViewStyle } from 'react-native';
import { colors, radius } from '../lib/theme';

export function Button({ title, onPress, variant = 'primary', disabled, loading, style }: {
  title: string; onPress: () => void; variant?: 'primary' | 'secondary'; disabled?: boolean; loading?: boolean; style?: ViewStyle;
}) {
  const secondary = variant === 'secondary';
  return (
    <Pressable onPress={onPress} disabled={disabled || loading}
      style={({ pressed }) => [s.btn, secondary && s.btnSecondary, (disabled || pressed) && { opacity: 0.7 }, style]}>
      {loading ? <ActivityIndicator color={secondary ? colors.greenDark : '#fff'} />
        : <Text style={[s.btnText, secondary && { color: colors.greenDark }]}>{title}</Text>}
    </Pressable>
  );
}

export function Field({ label, ...props }: { label: string } & TextInputProps) {
  return (
    <View style={{ marginBottom: 10 }}>
      <Text style={s.label}>{label}</Text>
      <TextInput placeholderTextColor="#9aa89e" {...props} style={[s.input, props.style]} />
    </View>
  );
}

export function Panel({ children, style }: { children: ReactNode; style?: ViewStyle }) {
  return <View style={[s.panel, style]}>{children}</View>;
}

export function Chip({ label, active, onPress }: { label: string; active?: boolean; onPress: () => void }) {
  return (
    <Pressable onPress={onPress} style={[s.chip, active && s.chipActive]}>
      <Text style={[s.chipText, active && { color: '#fff' }]}>{label}</Text>
    </Pressable>
  );
}

export const ErrorText = ({ children }: { children?: string }) =>
  children ? <Text style={s.error}>{children}</Text> : null;

export const Empty = ({ children }: { children: string }) => <Text style={s.empty}>{children}</Text>;

export function Tag({ done, children }: { done?: boolean; children: string }) {
  return (
    <View style={[s.tag, done && { backgroundColor: '#e8e8e8' }]}>
      <Text style={[s.tagText, done && { color: '#555' }]}>{children}</Text>
    </View>
  );
}

const s = StyleSheet.create({
  btn: { backgroundColor: colors.green, borderRadius: 12, paddingVertical: 13, paddingHorizontal: 18, alignItems: 'center' },
  btnSecondary: { backgroundColor: colors.leaf },
  btnText: { color: '#fff', fontWeight: '700', fontSize: 16 },
  label: { fontWeight: '600', fontSize: 13, marginBottom: 4, color: colors.ink },
  input: { borderWidth: 1, borderColor: colors.line, borderRadius: 10, backgroundColor: '#fff', paddingHorizontal: 12, paddingVertical: 10, fontSize: 16, color: colors.ink },
  panel: { backgroundColor: '#fff', borderWidth: 1, borderColor: colors.line, borderRadius: radius, padding: 16, marginBottom: 14 },
  chip: { paddingHorizontal: 14, paddingVertical: 8, borderRadius: 99, backgroundColor: colors.leaf, marginRight: 8 },
  chipActive: { backgroundColor: colors.green },
  chipText: { color: colors.greenDark, fontWeight: '600' },
  error: { color: colors.danger, fontWeight: '600', marginVertical: 6 },
  empty: { textAlign: 'center', color: colors.muted, padding: 32 },
  tag: { alignSelf: 'flex-start', backgroundColor: colors.leaf, borderRadius: 99, paddingHorizontal: 10, paddingVertical: 3 },
  tagText: { color: colors.greenDark, fontWeight: '600', fontSize: 12 },
});
