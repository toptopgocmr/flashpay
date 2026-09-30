import 'package:flutter/material.dart';
import '../config/theme.dart';
import '../l10n/l10n.dart';

/// Petits composants communs aux écrans ajoutés (cahier des charges v1.5).
String fpDate(dynamic iso, {bool time = true}) {
  final d = DateTime.tryParse('${iso ?? ''}')?.toLocal();
  if (d == null) return '';
  String two(int n) => n.toString().padLeft(2, '0');
  final day = '${two(d.day)}/${two(d.month)}/${d.year}';
  return time ? '$day ${two(d.hour)}:${two(d.minute)}' : day;
}

int fpInt(dynamic v) => v is num ? v.toInt() : int.tryParse('${v ?? ''}') ?? 0;

class FpSectionTitle extends StatelessWidget {
  final String text;
  final Widget? trailing;
  const FpSectionTitle(this.text, {super.key, this.trailing});

  @override
  Widget build(BuildContext context) => Padding(
        padding: EdgeInsets.only(top: 18, bottom: 8),
        child: Row(children: [
          Expanded(child: Text(tr(text), style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold))),
          if (trailing != null) trailing!,
        ]),
      );
}

class FpEmpty extends StatelessWidget {
  final String text;
  final IconData icon;
  const FpEmpty(this.text, {super.key, this.icon = Icons.inbox_outlined});

  @override
  Widget build(BuildContext context) => Padding(
        padding: EdgeInsets.symmetric(vertical: 32),
        child: Column(children: [
          Icon(icon, size: 40, color: Colors.black26),
          SizedBox(height: 8),
          Text(tr(text), textAlign: TextAlign.center, style: TextStyle(color: Colors.black54)),
        ]),
      );
}

class FpStatusChip extends StatelessWidget {
  final String label;
  final String tone; // ok | warn | err | info
  FpStatusChip(this.label, {super.key, this.tone = 'info'});

  @override
  Widget build(BuildContext context) {
    final (bg, fg) = switch (tone) {
      'ok' => (Color(0xFFDCFCE7), FpColors.success),
      'warn' => (Color(0xFFFEF9C3), Color(0xFFA16207)),
      'err' => (Color(0xFFFEE2E2), FpColors.danger),
      _ => (FpColors.soft, FpColors.navy),
    };
    return Container(
      padding: EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(20)),
      child: Text(tr(label), style: TextStyle(color: fg, fontSize: 12, fontWeight: FontWeight.w600)),
    );
  }
}

/// Carte d'information / d'alerte (mode dégradé, KYC, plafonds…).
class FpBanner extends StatelessWidget {
  final String text;
  final IconData icon;
  final Color color;
  final VoidCallback? onTap;
  const FpBanner(this.text, {super.key, this.icon = Icons.info_outline, this.color = const Color(0xFFA16207), this.onTap});

  @override
  Widget build(BuildContext context) => InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(12),
        child: Container(
          margin: EdgeInsets.only(bottom: 12),
          padding: EdgeInsets.all(12),
          decoration: BoxDecoration(color: color.withOpacity(.10), borderRadius: BorderRadius.circular(12)),
          child: Row(children: [
            Icon(icon, color: color),
            SizedBox(width: 10),
            Expanded(child: Text(tr(text), style: TextStyle(color: color, fontSize: 13))),
            if (onTap != null) Icon(Icons.chevron_right, color: color),
          ]),
        ),
      );
}

/// Champ montant simple.
class FpAmountField extends StatelessWidget {
  final TextEditingController controller;
  final String label;
  final String currency;
  const FpAmountField({super.key, required this.controller, this.label = 'Montant', this.currency = 'XAF'});

  @override
  Widget build(BuildContext context) => TextField(
        controller: controller,
        keyboardType: TextInputType.number,
        decoration: InputDecoration(labelText: label, suffixText: currency, prefixIcon: Icon(Icons.payments_outlined)),
      );
}

/// Encadré d'erreur rouge clair (formulaires d'authentification).
class FpErrorBox extends StatelessWidget {
  final String text;
  const FpErrorBox(this.text, {super.key});

  @override
  Widget build(BuildContext context) => Container(
        padding: EdgeInsets.all(12),
        decoration: BoxDecoration(color: Color(0xFFFEE2E2), borderRadius: BorderRadius.circular(14)),
        child: Row(children: [
          Icon(Icons.error_outline_rounded, color: FpColors.danger),
          SizedBox(width: 8),
          Expanded(child: Text(tr(text), style: TextStyle(color: FpColors.danger))),
        ]),
      );
}
