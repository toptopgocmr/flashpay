import 'package:flutter/material.dart';
import '../config/theme.dart';
import 'features_service.dart';

/// Comptes liés au profil utilisés comme comptes À DÉBITER :
/// le mobile money par défaut et la carte par défaut sont proposés
/// automatiquement dans Recharger, Envoyer et Payer un marchand.
class LinkedSources {
  static List<Map<String, dynamic>>? _cache;

  /// Source présélectionnée par « Payer un marchand » (wallet | mobile | card),
  /// reprise par l'écran de paiement ouvert après scan QR / NFC / code.
  static String preferredPaySource = 'wallet';

  /// Lit puis réinitialise la source choisie (un scan ultérieur repart du wallet).
  static String takePaySource() {
    final s = preferredPaySource;
    preferredPaySource = 'wallet';
    return s;
  }

  static Future<List<Map<String, dynamic>>> load({bool refresh = false}) async {
    if (!refresh && _cache != null) return _cache!;
    try {
      _cache = await FeaturesService().linkedAccounts();
    } catch (_) {
      _cache ??= [];
    }
    return _cache!;
  }

  static void clear() => _cache = null;

  static Map<String, dynamic>? _default(List<Map<String, dynamic>> all, String type) {
    final list = all.where((a) => a['type'] == type).toList();
    if (list.isEmpty) return null;
    return list.firstWhere((a) => a['is_default'] == true || a['is_default'] == 1, orElse: () => list.first);
  }

  /// Numéro mobile money lié par défaut (format international +242…), sinon null.
  static Future<String?> defaultMobilePhone() async {
    final a = _default(await load(), 'mobile_money');
    final p = a?['phone']?.toString();
    if (p == null || p.isEmpty) return null;
    return p.startsWith('+') ? p : '+$p';
  }

  /// Carte liée par défaut : {card_brand, card_last4, card_holder, …}, sinon null.
  static Future<Map<String, dynamic>?> defaultCard() async => _default(await load(), 'card');
}

/// Logo Visa / Mastercard (assets/images/operators).
class FpCardBrandLogo extends StatelessWidget {
  final String brand; // Visa | Mastercard
  final double height;
  const FpCardBrandLogo(this.brand, {super.key, this.height = 18});

  @override
  Widget build(BuildContext context) {
    final b = brand.toLowerCase();
    final asset = b.contains('master') ? 'assets/images/operators/mastercard.png' : (b.contains('visa') ? 'assets/images/operators/visa.png' : null);
    if (asset == null) return Icon(Icons.credit_card_rounded, size: height + 4, color: FpColors.navy);
    return Image.asset(asset, height: height, fit: BoxFit.contain);
  }
}

/// Les deux logos côte à côte (« Visa · Mastercard »).
class FpCardBrands extends StatelessWidget {
  final double height;
  const FpCardBrands({super.key, this.height = 16});

  @override
  Widget build(BuildContext context) => Row(mainAxisSize: MainAxisSize.min, children: [
        FpCardBrandLogo('Visa', height: height),
        const SizedBox(width: 6),
        FpCardBrandLogo('Mastercard', height: height),
      ]);
}

/// Bandeau « Carte débitée : [logo] •••• 1234 · Titulaire » (ou invitation à lier une carte).
class FpLinkedCardBanner extends StatelessWidget {
  final Map<String, dynamic>? card;
  final VoidCallback? onLink;
  const FpLinkedCardBanner({super.key, required this.card, this.onLink});

  @override
  Widget build(BuildContext context) {
    final c = card;
    return Container(
      margin: const EdgeInsets.only(top: 12),
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(14), border: Border.all(color: const Color(0xFFE5E7EB))),
      child: Row(children: [
        c != null ? FpCardBrandLogo('${c['card_brand'] ?? ''}', height: 22) : const FpCardBrands(height: 18),
        const SizedBox(width: 12),
        Expanded(
          child: c != null
              ? Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text('Carte débitée · •••• ${c['card_last4'] ?? ''}', style: const TextStyle(fontWeight: FontWeight.w600)),
                  Text('${c['card_holder'] ?? c['account_holder'] ?? c['label'] ?? ''} · validation 3-D Secure', style: const TextStyle(fontSize: 12, color: Colors.black54)),
                ])
              : const Text('Aucune carte liée à votre profil.', style: TextStyle(fontSize: 13)),
        ),
        if (c == null && onLink != null) TextButton(onPressed: onLink, child: const Text('Lier une carte')),
      ]),
    );
  }
}
