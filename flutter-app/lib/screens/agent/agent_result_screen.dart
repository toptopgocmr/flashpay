import 'package:flutter/material.dart';
import '../../config/theme.dart';
import '../../l10n/l10n.dart';

class AgentResultScreen extends StatelessWidget {
  final bool success;
  final String title;
  final String amount;
  final List<String> lines;
  const AgentResultScreen({super.key, required this.success, required this.title, required this.amount, this.lines = const []});

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(tr(title)), automaticallyImplyLeading: false),
        body: SafeArea(
          child: Padding(
            padding: EdgeInsets.all(24),
            child: Column(children: [
              Spacer(),
              Icon(success ? Icons.check_circle_rounded : Icons.cancel_rounded, size: 110, color: success ? FpColors.success : FpColors.danger),
              SizedBox(height: 20),
              Text(tr(title), style: TextStyle(fontSize: 22, fontWeight: FontWeight.w800)),
              SizedBox(height: 8),
              Text(amount, style: TextStyle(fontSize: 30, fontWeight: FontWeight.w900, color: FpColors.navy)),
              SizedBox(height: 12),
              ...lines.where((l) => l.isNotEmpty).map((l) => Padding(
                    padding: EdgeInsets.only(top: 4),
                    child: Text(l, textAlign: TextAlign.center, style: TextStyle(color: Colors.black54)),
                  )),
              Spacer(),
              ElevatedButton(onPressed: () => Navigator.pop(context), child: Text(tr('Terminé'))),
            ]),
          ),
        ),
      );
}
