//! Whether this computer is a virtual machine or a remote session (docs/DEVELOPMENT_PLAN.md §16). Only hardware and
//! session facts are read: the computer's manufacturer and model, the BIOS vendor, the hypervisor bit and whether the
//! session is a Remote Desktop one. Nothing about what the person does. The result is a flag for a manager to look at,
//! never a reason to stop tracking.

use std::sync::Mutex;
use std::time::{Duration, Instant};

use serde::Serialize;

use super::imp;

#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize)]
#[serde(rename_all = "snake_case")]
pub enum Environment {
    Physical,
    VirtualMachine,
    RemoteSession,
}

impl Environment {
    /// The word the server expects.
    pub fn wire(self) -> &'static str {
        match self {
            Environment::Physical => "physical",
            Environment::VirtualMachine => "virtual_machine",
            Environment::RemoteSession => "remote_session",
        }
    }
}

/// What the platform could read about the machine.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct Facts {
    pub manufacturer: String,
    pub product: String,
    pub bios_vendor: String,
    /// The CPU reports that a hypervisor is present. A Windows host with Hyper-V or virtualization-based security
    /// reports it too, so it is never enough on its own.
    pub hypervisor_present: bool,
    pub remote_session: bool,
}

/// Names that computer manufacturers, models and BIOS vendors of virtual machines carry (lowercase).
const VIRTUAL_SIGNATURES: &[&str] = &[
    "vmware",
    "virtualbox",
    "vbox",
    "virtual machine",
    "qemu",
    "kvm",
    "xen",
    "bochs",
    "parallels",
    "hyper-v",
    "innotek",
    "bhyve",
    "red hat",
    "amazon ec2",
];

pub fn classify(facts: &Facts) -> Environment {
    let text = format!("{} {} {}", facts.manufacturer, facts.product, facts.bios_vendor).to_lowercase();
    let named = VIRTUAL_SIGNATURES.iter().any(|name| text.contains(name));
    // QEMU and KVM guests often call themselves a "Standard PC": with a hypervisor present that is a virtual machine.
    let generic = facts.hypervisor_present && text.contains("standard pc");

    if named || generic {
        Environment::VirtualMachine
    } else if facts.remote_session {
        Environment::RemoteSession
    } else {
        Environment::Physical
    }
}

/// How long a reading stays valid: the machine does not change while the app runs, but a session can become remote.
const REFRESH: Duration = Duration::from_secs(60 * 60);

static CACHE: Mutex<Option<(Instant, Environment)>> = Mutex::new(None);

/// The environment of this computer, read at most once an hour.
pub fn current() -> Environment {
    let mut cache = CACHE.lock().unwrap_or_else(|e| e.into_inner());
    if let Some((at, value)) = *cache {
        if at.elapsed() < REFRESH {
            return value;
        }
    }
    let value = classify(&imp::environment_facts());
    *cache = Some((Instant::now(), value));
    value
}

#[cfg(test)]
mod tests {
    use super::*;

    fn facts(manufacturer: &str, product: &str, bios: &str) -> Facts {
        Facts {
            manufacturer: manufacturer.into(),
            product: product.into(),
            bios_vendor: bios.into(),
            ..Facts::default()
        }
    }

    #[test]
    fn well_known_virtual_machines_are_recognised() {
        assert_eq!(classify(&facts("Microsoft Corporation", "Virtual Machine", "Microsoft Corporation")), Environment::VirtualMachine);
        assert_eq!(classify(&facts("VMware, Inc.", "VMware7,1", "VMware, Inc.")), Environment::VirtualMachine);
        assert_eq!(classify(&facts("innotek GmbH", "VirtualBox", "innotek GmbH")), Environment::VirtualMachine);
        assert_eq!(classify(&facts("QEMU", "Standard PC (Q35 + ICH9, 2009)", "SeaBIOS")), Environment::VirtualMachine);
    }

    #[test]
    fn real_computers_are_physical_even_with_a_hypervisor_running() {
        let mut real = facts("Dell Inc.", "Latitude 5420", "Dell Inc.");
        assert_eq!(classify(&real), Environment::Physical);
        // a host with Hyper-V or virtualization-based security reports a hypervisor: that alone means nothing
        real.hypervisor_present = true;
        assert_eq!(classify(&real), Environment::Physical);
    }

    #[test]
    fn a_generic_standard_pc_is_virtual_only_when_a_hypervisor_is_present() {
        let mut generic = facts("Unknown", "Standard PC", "Unknown");
        assert_eq!(classify(&generic), Environment::Physical);
        generic.hypervisor_present = true;
        assert_eq!(classify(&generic), Environment::VirtualMachine);
    }

    #[test]
    fn a_remote_session_on_a_real_computer_is_reported_as_remote() {
        let mut remote = facts("Dell Inc.", "Latitude 5420", "Dell Inc.");
        remote.remote_session = true;
        assert_eq!(classify(&remote), Environment::RemoteSession);
        // a virtual machine wins over a remote session
        remote.product = "VMware7,1".into();
        assert_eq!(classify(&remote), Environment::VirtualMachine);
    }

    #[test]
    fn this_computer_can_be_read_without_a_crash() {
        // whatever the machine is, reading it works and gives one of the three answers
        let value = current();
        assert!(matches!(value, Environment::Physical | Environment::VirtualMachine | Environment::RemoteSession));
        eprintln!("this computer: {}", value.wire());
    }

    #[test]
    fn the_server_gets_the_words_it_expects() {
        assert_eq!(Environment::Physical.wire(), "physical");
        assert_eq!(Environment::VirtualMachine.wire(), "virtual_machine");
        assert_eq!(Environment::RemoteSession.wire(), "remote_session");
    }
}
