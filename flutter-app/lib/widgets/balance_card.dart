import 'package:flutter/material.dart';
import '../config/theme.dart';
import '../l10n/l10n.dart';

/// Carte de solde façon Wave : dégradé bleu, montant en évidence,
/// bouton "afficher/masquer" le solde.
class BalanceCard extends StatefulWidget {
  final String formattedBalance;
  final String label;

  const BalanceCard({super.key, required this.formattedBalance, this.label = 'Solde disponible'});

  @override
  State<BalanceCard> createState() => _BalanceCardState();
}

class _BalanceCardState extends State<BalanceCard> {
  bool _hidden = false;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: EdgeInsets.all(20),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(24),
        gradient: FpColors.heroGradient,
        boxShadow: [BoxShadow(color: FpColors.navy.withOpacity(.25), blurRadius: 18, offset: Offset(0, 8))],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Text(tr(widget.label), style: TextStyle(color: Colors.white70, fontSize: 13)),
              Spacer(),
              GestureDetector(
                onTap: () => setState(() => _hidden = !_hidden),
                child: Icon(_hidden ? Icons.visibility_off_rounded : Icons.visibility_rounded,
                    color: Colors.white70, size: 18),
              ),
            ],
          ),
          SizedBox(height: 10),
          Text(
            _hidden ? '••••••' : widget.formattedBalance,
            style: TextStyle(color: Colors.white, fontSize: 32, fontWeight: FontWeight.w800, letterSpacing: .5),
          ),
        ],
      ),
    );
  }
}
