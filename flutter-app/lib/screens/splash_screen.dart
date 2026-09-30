import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../config/theme.dart';
import '../widgets/fp_logo.dart';
import '../models/user.dart';
import '../providers/session_provider.dart';
import 'auth/profile_select_screen.dart';
import 'home_router.dart';
import '../l10n/l10n.dart';

class SplashScreen extends StatefulWidget {
  const SplashScreen({super.key});

  @override
  State<SplashScreen> createState() => _SplashScreenState();
}

class _SplashScreenState extends State<SplashScreen> {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _bootstrap());
  }

  Future<void> _bootstrap() async {
    final session = context.read<SessionProvider>();
    await session.bootstrap();
    if (!mounted) return;

    Widget destination;
    if (session.status == SessionStatus.authenticated) {
      destination = homeFor(session.activeProfile);
    } else {
      destination = const ProfileSelectScreen();
    }

    Navigator.pushReplacement(context, MaterialPageRoute(builder: (_) => destination));
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: FpColors.navy,
      body: Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            FpLogo(size: 88, chip: true),
            SizedBox(height: 16),
            Text(tr('FlashPay'), style: TextStyle(color: Colors.white, fontSize: 28, fontWeight: FontWeight.bold)),
            SizedBox(height: 24),
            CircularProgressIndicator(color: Colors.white),
          ],
        ),
      ),
    );
  }
}
