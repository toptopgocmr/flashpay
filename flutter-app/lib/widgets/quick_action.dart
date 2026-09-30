import 'package:flutter/material.dart';
import '../config/theme.dart';
import '../l10n/l10n.dart';

/// Bouton d'action rapide façon Wave : pastille ronde bleu clair,
/// icône bleue, libellé court dessous.
class QuickAction extends StatelessWidget {
  final IconData icon;
  final String label;
  final VoidCallback onTap;
  final Color color;

  const QuickAction({
    super.key,
    required this.icon,
    required this.label,
    required this.onTap,
    this.color = FpColors.soft,
  });

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(16),
      child: SizedBox(
        width: 78,
        child: Column(
          children: [
            Container(
              width: 58,
              height: 58,
              decoration: BoxDecoration(color: color, shape: BoxShape.circle),
              child: Icon(icon, color: FpColors.navy, size: 26),
            ),
            SizedBox(height: 8),
            Text(tr(label),
                textAlign: TextAlign.center,
                style: TextStyle(fontSize: 12.5, fontWeight: FontWeight.w600, color: FpColors.ink)),
          ],
        ),
      ),
    );
  }
}
