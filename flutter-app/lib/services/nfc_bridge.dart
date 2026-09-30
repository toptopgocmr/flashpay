import 'dart:convert';
import 'dart:typed_data';
import 'package:flutter/foundation.dart' show kIsWeb, defaultTargetPlatform, TargetPlatform;
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:nfc_manager/nfc_manager.dart';
import 'package:nfc_manager/platform_tags.dart';
import '../config/theme.dart';

/// NFC téléphone ↔ téléphone (CDC §4.4).
///
/// ÉMISSION (Android) : le téléphone qui « montre » un code (mon QR, code de
/// paiement / dépôt, QR marchand, QR agent, QR dynamique) le publie aussi par
/// émulation de carte (HCE) — service natif `FlashPayHceService`, AID
/// F0 46 4C 41 53 48 50 (« FLASHP »). Le code n'est servi que tant que l'écran
/// est ouvert, 2 minutes maximum, téléphone déverrouillé.
///
/// LECTURE (Android, iPhone pour les tags) : le téléphone qui « lit »
/// sélectionne l'AID FlashPay (ISO-DEP). À défaut, il lit un tag NDEF
/// classique (autocollant / TPE). Le texte obtenu est le même lien
/// `flashpay://…` que dans le QR : les écrans le traitent de la même façon.
class FpNfc {
  FpNfc._();

  static const _channel = MethodChannel('flashpay/hce');
  static const List<int> aid = [0xF0, 0x46, 0x4C, 0x41, 0x53, 0x48, 0x50];

  static bool get _android => !kIsWeb && defaultTargetPlatform == TargetPlatform.android;

  /// Ce téléphone peut-il se faire lire comme une carte (HCE) ?
  static Future<bool> canEmit() async {
    if (!_android) return false;
    try {
      return await _channel.invokeMethod<bool>('isSupported') ?? false;
    } catch (_) {
      return false;
    }
  }

  /// Publie [payload] par NFC pendant [ttl] (au plus ~240 octets).
  static Future<bool> emit(String payload, {Duration ttl = const Duration(minutes: 2)}) async {
    if (!_android || utf8.encode(payload).length > 240) return false;
    try {
      return await _channel.invokeMethod<bool>('setPayload', {'payload': payload, 'ttlSeconds': ttl.inSeconds}) ?? false;
    } catch (_) {
      return false;
    }
  }

  static Future<void> stop() async {
    if (!_android) return;
    try {
      await _channel.invokeMethod('clear');
    } catch (_) {}
  }

  /// Lecture NFC possible sur ce téléphone ?
  static Future<bool> canRead() async {
    if (kIsWeb) return false;
    try {
      return await NfcManager.instance.isAvailable();
    } catch (_) {
      return false;
    }
  }

  /// Extrait le lien FlashPay d'un tag : téléphone FlashPay (HCE) puis NDEF.
  static Future<String?> rawFromTag(NfcTag tag) async {
    final iso = IsoDep.from(tag);
    if (iso != null) {
      try {
        final apdu = Uint8List.fromList([0x00, 0xA4, 0x04, 0x00, aid.length, ...aid, 0x00]);
        final res = await iso.transceive(data: apdu);
        final n = res.length;
        if (n > 2 && res[n - 2] == 0x90 && res[n - 1] == 0x00) {
          return utf8.decode(res.sublist(0, n - 2), allowMalformed: true);
        }
      } catch (_) {}
    }
    final msg = Ndef.from(tag)?.cachedMessage;
    if (msg != null) {
      for (final r in msg.records) {
        final text = String.fromCharCodes(r.payload);
        final i = text.indexOf('flashpay://');
        if (i >= 0) return text.substring(i);
        final fpm = RegExp(r'FP[MO]-[A-Z0-9]+').firstMatch(text)?.group(0);
        if (fpm != null) return fpm;
        // Enregistrement URI « bien connu » : 1er octet = préfixe abrégé
        if (r.payload.length > 1 && r.payload.first <= 0x04) {
          const prefixes = ['', 'http://www.', 'https://www.', 'http://', 'https://'];
          return prefixes[r.payload.first] + String.fromCharCodes(r.payload.sublist(1));
        }
      }
    }
    return null;
  }

  /// Feuille « Approchez le téléphone » : renvoie le lien lu, ou null.
  static Future<String?> readSheet(BuildContext context, {String title = 'Approchez les deux téléphones', String? hint}) async {
    if (!await canRead()) {
      if (context.mounted) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text('NFC indisponible ou désactivé sur ce téléphone. Activez-le dans les réglages ou utilisez le QR code.'),
        ));
      }
      return null;
    }
    if (!context.mounted) return null;
    return showModalBottomSheet<String>(
      context: context,
      isDismissible: true,
      builder: (_) => _NfcReadSheet(title: title, hint: hint),
    );
  }

  /// Numéro contenu dans un lien `flashpay://pay?phone=242…` (ou saisi tel quel).
  static String? phoneFromLink(String raw) {
    final uri = Uri.tryParse(raw.trim());
    final p = (uri != null && uri.scheme == 'flashpay') ? uri.queryParameters['phone'] : null;
    if (p != null && p.isNotEmpty) return '+${p.replaceAll('+', '')}';
    final digits = raw.replaceAll(RegExp(r'\D'), '');
    return digits.length >= 9 && digits.length <= 15 && !raw.contains('://') ? '+$digits' : null;
  }
}

class _NfcReadSheet extends StatefulWidget {
  final String title;
  final String? hint;
  const _NfcReadSheet({required this.title, this.hint});

  @override
  State<_NfcReadSheet> createState() => _NfcReadSheetState();
}

class _NfcReadSheetState extends State<_NfcReadSheet> with SingleTickerProviderStateMixin {
  late final AnimationController _pulse = AnimationController(vsync: this, duration: const Duration(milliseconds: 1200))..repeat(reverse: true);
  String? _error;
  bool _done = false;

  @override
  void initState() {
    super.initState();
    NfcManager.instance.startSession(onDiscovered: (NfcTag tag) async {
      final raw = await FpNfc.rawFromTag(tag);
      if (raw == null) {
        if (mounted) setState(() => _error = 'Aucun code FlashPay lu. Ouvrez l\'écran du QR sur l\'autre téléphone, puis réessayez.');
        return;
      }
      _done = true;
      await NfcManager.instance.stopSession().catchError((_) {});
      if (mounted) Navigator.pop(context, raw);
    });
  }

  @override
  void dispose() {
    _pulse.dispose();
    if (!_done) NfcManager.instance.stopSession().catchError((_) {});
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(24, 0, 24, 24),
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            ScaleTransition(
              scale: Tween(begin: .92, end: 1.06).animate(_pulse),
              child: Container(
                width: 110,
                height: 110,
                decoration: const BoxDecoration(color: FpColors.rose, shape: BoxShape.circle),
                child: const Icon(Icons.nfc_rounded, size: 56, color: FpColors.red),
              ),
            ),
            const SizedBox(height: 18),
            Text(widget.title, textAlign: TextAlign.center, style: const TextStyle(fontSize: 19, fontWeight: FontWeight.w700)),
            const SizedBox(height: 8),
            Text(
              _error ?? widget.hint ?? 'Placez le dos des deux téléphones l\'un contre l\'autre. L\'autre téléphone doit afficher son code FlashPay, écran allumé et déverrouillé.',
              textAlign: TextAlign.center,
              style: TextStyle(color: _error != null ? FpColors.danger : FpColors.muted),
            ),
            const SizedBox(height: 16),
            TextButton(onPressed: () => Navigator.pop(context), child: const Text('Annuler')),
          ]),
        ),
      );
}

/// Publie [payload] par NFC tant que l'enfant est affiché (écrans « Mon QR »,
/// code de paiement, QR marchand / agent, QR dynamique) et affiche une
/// pastille « NFC actif » quand le téléphone sait émettre.
class FpNfcBeacon extends StatefulWidget {
  final String? payload;
  final Widget child;
  final bool showBadge;
  const FpNfcBeacon({super.key, required this.payload, required this.child, this.showBadge = true});

  @override
  State<FpNfcBeacon> createState() => _FpNfcBeaconState();
}

class _FpNfcBeaconState extends State<FpNfcBeacon> {
  /// Écrans émetteurs empilés : seul le plus récent publie ; à sa fermeture,
  /// celui du dessous reprend la main.
  static final List<_FpNfcBeaconState> _stack = [];
  bool _active = false;

  @override
  void initState() {
    super.initState();
    _publish();
  }

  @override
  void didUpdateWidget(covariant FpNfcBeacon old) {
    super.didUpdateWidget(old);
    if (old.payload != widget.payload) _publish();
  }

  Future<void> _publish() async {
    _stack.remove(this);
    _stack.add(this);
    final p = widget.payload;
    if (p == null || p.isEmpty) {
      await FpNfc.stop();
      if (mounted && _active) setState(() => _active = false);
      return;
    }
    final ok = await FpNfc.emit(p);
    if (mounted && ok != _active) setState(() => _active = ok);
  }

  @override
  void dispose() {
    final wasTop = _stack.isNotEmpty && identical(_stack.last, this);
    _stack.remove(this);
    if (wasTop) {
      if (_stack.isNotEmpty) {
        _stack.last._publish();
      } else {
        FpNfc.stop();
      }
    }
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    if (!widget.showBadge || !_active) return widget.child;
    return Column(mainAxisSize: MainAxisSize.min, children: [
      widget.child,
      const SizedBox(height: 10),
      Container(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
        decoration: BoxDecoration(color: FpColors.rose, borderRadius: BorderRadius.circular(20)),
        child: const Row(mainAxisSize: MainAxisSize.min, children: [
          Icon(Icons.nfc_rounded, size: 16, color: FpColors.red),
          SizedBox(width: 6),
          Text('NFC actif : approchez l\'autre téléphone', style: TextStyle(fontSize: 12.5, color: FpColors.red, fontWeight: FontWeight.w600)),
        ]),
      ),
    ]);
  }
}
