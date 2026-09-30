import 'package:flutter/material.dart';
import '../../services/nfc_bridge.dart';
import 'package:provider/provider.dart';
import 'package:qr_flutter/qr_flutter.dart';
import '../../config/theme.dart';
import '../../providers/session_provider.dart';
import '../../widgets/fp_design.dart';
import '../../l10n/l10n.dart';

/// « Mon code QR » de l'agent : le client le scanne depuis son application.
///  - pour un RETRAIT : son téléphone génère alors le code de retrait à
///    présenter (l'agent le valide dans « Retrait client ») ;
///  - pour un DÉPÔT : le client identifie le point de vente ; l'agent
///    encaisse les espèces via « Dépôt client ».
class AgentQrScreen extends StatelessWidget {
  final String? agentCode;
  final String? posCode;
  const AgentQrScreen({super.key, this.agentCode, this.posCode});

  @override
  Widget build(BuildContext context) {
    final user = context.watch<SessionProvider>().user;
    final code = agentCode ?? '${user?.agent?['agent_code'] ?? ''}';
    final pos = posCode ?? '${user?.agent?['pos_code'] ?? ''}';
    final payload = 'flashpay://agent?a=$code${pos.isNotEmpty ? '&p=$pos' : ''}';

    return Scaffold(
      appBar: AppBar(title: Text(tr('Mon code QR'))),
      body: ListView(
        padding: EdgeInsets.all(20),
        children: [
          Container(
            padding: EdgeInsets.all(24),
            decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(26), border: Border.all(color: FpColors.line)),
            child: Column(children: [
              Text(user?.fullName ?? '', style: TextStyle(fontSize: 18, fontWeight: FontWeight.w600)),
              SizedBox(height: 4),
              Text('Agent $code${pos.isNotEmpty ? ' · PV $pos' : ''}', style: TextStyle(color: FpColors.muted)),
              SizedBox(height: 20),
              code.isEmpty
                  ? Padding(padding: EdgeInsets.all(40), child: Text(tr('Identifiant agent indisponible.')))
                  : FpNfcBeacon(payload: payload, child: QrImageView(data: payload, size: 230, eyeStyle: QrEyeStyle(eyeShape: QrEyeShape.square, color: FpColors.navy), dataModuleStyle: QrDataModuleStyle(dataModuleShape: QrDataModuleShape.square, color: FpColors.navy))),
              SizedBox(height: 16),
              SelectableText(code, style: TextStyle(fontSize: 22, fontWeight: FontWeight.w700, letterSpacing: 2, color: FpColors.navy)),
            ]),
          ),
          SizedBox(height: 18),
          FpPromptCard(
            icon: Icons.arrow_downward_rounded,
            tone: FpTone.red,
            title: tr('Dépôt'),
            subtitle: tr('Le client vous remet les espèces : son wallet est crédité depuis votre float.'),
            onTap: _noop,
          ),
          FpPromptCard(
            icon: Icons.arrow_upward_rounded,
            tone: FpTone.blue,
            title: tr('Retrait'),
            subtitle: tr('Le client scanne, reçoit son code de retrait et vous le présente.'),
            onTap: _noop,
          ),
        ],
      ),
    );
  }
}

void _noop() {}
